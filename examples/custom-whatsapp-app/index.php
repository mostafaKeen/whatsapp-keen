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
$errorMessage = null;
$isRegistered = true; // Always active, no connector required

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <style>
        :root {
            --primary: #25D366;
            --primary-dark: #128C7E;
            --secondary: #34B7F1;
            --bg: #f8fafc;
            --card-bg: rgba(255, 255, 255, 0.95);
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 16px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #f1fdf4 100%);
            color: var(--text-main);
            min-height: 100vh;
            padding-bottom: 2rem;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        h1, h2, h3, h4, .modal-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .app-logo {
            font-size: 2rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-modern {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow);
        }

        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-modern:active {
            transform: translateY(0);
        }

        .btn-primary-modern { background: var(--primary); color: white; }
        .btn-primary-modern:hover { background: var(--primary-dark); color: white; }
        .btn-info-modern { background: var(--secondary); color: white; }
        .btn-info-modern:hover { background: #0ea5e9; color: white; }
        .btn-outline-modern { 
            background: transparent; 
            border: 1px solid var(--border); 
            color: var(--text-main);
            box-shadow: none;
        }
        .btn-outline-modern:hover { background: #f1f5f9; border-color: #cbd5e1; }

        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
        }

        .table-modern thead th {
            border: none;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 0 1.5rem;
        }

        .table-modern tbody tr {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: transform 0.2s ease;
        }

        .table-modern tbody tr:hover {
            transform: scale(1.005);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }

        .table-modern tbody td {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .table-modern tbody td:first-child {
            border-left: 1px solid var(--border);
            border-radius: 12px 0 0 12px;
        }

        .table-modern tbody td:last-child {
            border-right: 1px solid var(--border);
            border-radius: 0 12px 12px 0;
        }

        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 1.5rem 2rem;
            background: white;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border);
            padding: 1.5rem 2rem;
            background: #f8fafc;
        }

        .form-control-modern {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05);
        }

        .form-control-modern:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.1);
            outline: none;
        }

        .status-pill {
            padding: 0.4rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .status-pill.approved { background: #dcfce7; color: #166534; }
        .status-pill.pending { background: #fef9c3; color: #854d0e; }
        .status-pill.rejected { background: #fee2e2; color: #991b1b; }

            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(8px);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header Section -->
        <div class="header-section">
            <div class="app-logo">
                <i class="fab fa-whatsapp"></i>
                <span>KEEN WABA</span>
            </div>
            <?php if (!$errorMessage): ?>
                <div class="badge-active">
                    <span class="d-inline-block" style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; animation: pulse 2s infinite;"></span>
                    Integration Active
                </div>
            <?php endif; ?>
        </div>

        <!-- Alerts Section -->
        <?php if ($errorMessage): ?>
            <div class="glass-card border-left border-danger" style="border-left-width: 4px !important;">
                <div class="d-flex align-items-center text-danger">
                    <i class="fas fa-exclamation-circle fa-2x mr-3"></i>
                    <div>
                        <h5 class="mb-1 font-weight-bold">Initialization Error</h5>
                        <p class="mb-0 small opacity-75"><?= $errorMessage ?></p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="glass-card border-left border-success mb-4" style="border-left-width: 4px !important;">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center text-primary">
                        <div class="bg-success text-white p-3 rounded-circle mr-3 shadow-sm">
                            <i class="fas fa-check"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 font-weight-bold">System Online</h5>
                            <p class="mb-0 text-muted small">WhatsApp Business API is connected and ready.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isRegistered): ?>
            <!-- Main Content Section -->
            <div class="glass-card">
                <div class="d-flex justify-content-between align-items-end mb-4">
                    <div>
                        <h3 class="mb-1">Message Templates</h3>
                        <p class="text-muted small mb-0">Manage your WhatsApp approved message templates</p>
                    </div>
                    <div class="d-flex gap-2" style="gap: 8px;">
                        <button id="refreshTemplates" class="btn btn-modern btn-outline-modern" title="Refresh List">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <button id="campaignAnalysisBtn" class="btn btn-modern btn-outline-modern" data-toggle="modal" data-target="#campaignAnalysisModal">
                            <i class="fas fa-chart-line"></i> Insights
                        </button>
                        <button id="sendCampaignBtn" class="btn btn-modern btn-info-modern" data-toggle="modal" data-target="#campaignModal">
                            <i class="fas fa-paper-plane"></i> Send Bulk
                        </button>
                        <button id="createTemplateBtn" class="btn btn-modern btn-primary-modern" data-toggle="modal" data-target="#createTemplateModal">
                            <i class="fas fa-plus"></i> Create Template
                        </button>
                    </div>
                </div>

                <div id="templatesLoading" class="text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <p class="text-muted small">Synchronizing with Gupshup...</p>
                </div>
                
                <div id="templatesError" class="alert alert-danger" style="display:none; border-radius: 12px;"></div>
                
                <div id="templatesContainer" class="table-responsive" style="overflow: visible;">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Language</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="templatesList">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- API Response Banner -->
                <div id="apiResponseBanner" class="mt-4" style="display:none;">
                    <div class="alert alert-dismissible shadow-sm border-0 py-3 d-flex align-items-center" id="apiResponseAlert" role="alert">
                        <i class="fas fa-info-circle mr-2"></i>
                        <span id="apiResponseTitle" class="small flex-grow-1"></span>
                        <button type="button" class="close" onclick="$('#apiResponseBanner').hide()">&times;</button>
                    </div>
                </div>
            </div>
                <!-- Campaign Modal -->
                <div class="modal fade" id="campaignModal" tabindex="-1" data-backdrop="static">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form id="campaignForm">
                                <div class="modal-header">
                                    <h5 class="modal-title text-info"><i class="fas fa-paper-plane mr-2"></i> Send Bulk Campaign</h5>
                                    <button type="button" class="close" data-dismiss="modal" id="campaignCloseBtn">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <div class="form-group mb-4">
                                        <label class="font-weight-600 small text-muted text-uppercase">Select Template *</label>
                                        <select name="templateId" id="campaignTemplateSelect" class="form-control form-control-modern" required>
                                            <option value="">-- Choose an approved template --</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-4" id="campaignMediaUrlGroup" style="display:none;">
                                        <label class="font-weight-600 small text-muted text-uppercase">Media Header URL *</label>
                                        <input type="url" name="mediaUrl" id="campaignMediaUrl" class="form-control form-control-modern" placeholder="https://example.com/image.jpg">
                                        <small class="text-muted">Direct link to the media file required for this template.</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="font-weight-600 small text-muted text-uppercase mb-0">Target Numbers (One per line) *</label>
                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 mr-2" id="uploadCsvBtn">
                                                    <i class="fas fa-file-csv mr-1" id="csvBtnIcon"></i>
                                                    <span class="spinner-border spinner-border-sm mr-1" id="csvBtnSpinner" style="display:none;"></span>
                                                    Upload CSV
                                                </button>
                                                <input type="file" id="bulkCsvInput" accept=".csv" style="display: none;">
                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="toggleContactSelector">
                                                    <i class="fas fa-user-plus mr-1"></i> Add from Bitrix24
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Contact Selector -->
                                        <div id="contactSelectorSection" style="display:none;" class="mb-3 border rounded-lg bg-light overflow-hidden">
                                            <div class="p-3 bg-white border-bottom d-flex justify-content-between align-items-center">
                                                <input type="text" id="contactSearchInput" class="form-control form-control-sm form-control-modern" style="width: 200px;" placeholder="Search names...">
                                                <div class="d-flex align-items-center">
                                                    <div class="custom-control custom-checkbox mr-3">
                                                        <input type="checkbox" class="custom-control-input" id="selectAllContacts">
                                                        <label class="custom-control-label small" for="selectAllContacts">Select All</label>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-primary-modern py-1" id="applyContacts">Apply</button>
                                                </div>
                                            </div>
                                            <div class="contact-list-container px-3" id="contactListContainer">
                                                <div class="text-center p-4 text-muted small" id="contactsLoading">
                                                    <div class="spinner-border spinner-border-sm text-primary mb-2"></div><br>Fetching your Bitrix24 contacts...
                                                </div>
                                                <div id="contactList"></div>
                                                <div class="text-center p-2" id="loadMoreContactsContainer" style="display:none;">
                                                    <button type="button" class="btn btn-sm btn-link" id="loadMoreContacts">Load more...</button>
                                                </div>
                                            </div>
                                        </div>

                                        <textarea name="numbers" id="campaignNumbersArea" class="form-control form-control-modern" rows="6" placeholder="Format: 20100XXXXXXX" required></textarea>
                                        <div class="text-right mt-1"><small id="campaignNumberCount" class="badge badge-light text-muted">0 numbers detected</small></div>
                                    </div>

                                    <!-- Template Variables Mapping Section -->
                                    <div id="templateVariablesSection" style="display:none;" class="mt-4 p-4 border rounded-lg bg-light border-info">
                                        <h6 class="font-weight-bold mb-3 text-info"><i class="fas fa-magic mr-2"></i> Dynamic Personalization</h6>
                                        <p class="small text-muted mb-3">This template contains variables. Map them to your CSV columns or enter static values.</p>
                                        <div id="variableMappingList"></div>
                                    </div>

                                    <!-- Progress Area -->
                                    <div id="campaignStatusArea" style="display:none;" class="mt-4 p-4 border rounded-lg bg-white shadow-sm">
                                        <h6 class="font-weight-bold mb-3">Campaign Execution</h6>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span id="campaignStatusText" class="small text-primary font-weight-600">Initializing...</span>
                                            <span id="campaignStatTotal" class="small text-muted font-weight-600">0 / 0</span>
                                        </div>
                                        <div class="progress mb-3" style="height: 12px; border-radius: 6px; background: #f1f5f9;">
                                            <div id="campaignProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width: 0%;"></div>
                                        </div>
                                        <div class="row text-center no-gutters">
                                            <div class="col-6 border-right py-2">
                                                <div id="campaignStatSuccess" class="h5 font-weight-bold text-success mb-0">0</div>
                                                <div class="small text-muted text-uppercase font-weight-600" style="font-size: 10px;">Sent</div>
                                            </div>
                                            <div class="col-6 py-2">
                                                <div id="campaignStatFailed" class="h5 font-weight-bold text-danger mb-0">0</div>
                                                <div class="small text-muted text-uppercase font-weight-600" style="font-size: 10px;">Failed</div>
                                            </div>
                                        </div>
                                        <div class="mt-4 text-center" id="campaignControls">
                                            <button type="button" class="btn btn-sm btn-warning rounded-pill px-4" id="pauseCampaignBtn" style="display:none;"><i class="fas fa-pause mr-1"></i> Pause</button>
                                            <button type="button" class="btn btn-sm btn-success rounded-pill px-4" id="resumeCampaignBtn" style="display:none;"><i class="fas fa-play mr-1"></i> Resume</button>
                                        </div>
                                    </div>
                                    <input type="hidden" id="currentCampaignJobId" value="">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-modern btn-outline-modern" data-dismiss="modal">Cancel</button>
                                    <button type="submit" id="startCampaignBtn" class="btn btn-modern btn-info-modern">Start Campaign</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Campaign Analysis Modal -->
                <div class="modal fade" id="campaignAnalysisModal" tabindex="-1">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title text-secondary"><i class="fas fa-chart-bar mr-2"></i> Performance Analysis</h5>
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body p-0">
                                <div class="bg-light px-4 pt-3 border-bottom">
                                    <ul class="nav nav-pills mb-3" id="analysisTabs" role="tablist">
                                        <li class="nav-item">
                                            <a class="nav-link active rounded-pill px-4" id="campaigns-tab" data-toggle="tab" href="#campaigns-pane" role="tab">Bulk Campaigns</a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link rounded-pill px-4" id="templates-tab" data-toggle="tab" href="#templates-pane" role="tab">Approval Logs</a>
                                        </li>
                                    </ul>
                                </div>
                                <div class="tab-content px-4 py-4" id="analysisTabContent">
                                    <div class="tab-pane fade show active" id="campaigns-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table-modern">
                                                <thead>
                                                    <tr>
                                                        <th>Executed</th>
                                                        <th>Template</th>
                                                        <th>Targets</th>
                                                        <th>Sent</th>
                                                        <th>Delivered</th>
                                                        <th>Read</th>
                                                        <th>Failed</th>
                                                        <th class="text-right">Manage</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="campaignAnalysisList">
                                                    <tr><td colspan="8" class="text-center py-5 text-muted small">Loading insights data...</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="templates-pane" role="tabpanel">
                                        <div class="table-responsive">
                                            <table class="table-modern">
                                                <thead>
                                                    <tr>
                                                        <th>Timeline</th>
                                                        <th>Template Name</th>
                                                        <th>Event Type</th>
                                                        <th>Language</th>
                                                        <th>Status Details</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="templateStatusList">
                                                    <tr><td colspan="5" class="text-center py-5 text-muted small">Loading status histories...</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-modern btn-outline-modern" id="refreshAnalysisBtn"><i class="fas fa-sync mr-1"></i> Sync Data</button>
                                <button type="button" class="btn btn-modern btn-primary-modern" data-dismiss="modal">Done</button>
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
                                <div class="modal-header">
                                    <h5 class="modal-title text-success"><i class="fas fa-plus-circle mr-2"></i> Create WhatsApp Template</h5>
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Template Name *</label>
                                            <input type="text" name="elementName" class="form-control form-control-modern" placeholder="e.g. order_alert" required>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Category *</label>
                                            <select name="category" class="form-control form-control-modern" required>
                                                <option value="MARKETING">MARKETING</option>
                                                <option value="UTILITY">UTILITY</option>
                                                <option value="AUTHENTICATION">AUTHENTICATION</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Language *</label>
                                            <input type="text" name="languageCode" class="form-control form-control-modern" value="en_US" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Template Type *</label>
                                            <select name="templateType" id="templateType" class="form-control form-control-modern" required>
                                                <option value="TEXT" selected>TEXT</option>
                                                <option value="IMAGE">IMAGE</option>
                                                <option value="VIDEO">VIDEO</option>
                                                <option value="DOCUMENT">DOCUMENT</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Header (Optional)</label>
                                            <input type="text" name="header" id="headerField" class="form-control form-control-modern" placeholder="Max 60 chars">
                                        </div>
                                    </div>

                                    <div id="mediaExampleSection" class="p-4 mb-4 border rounded-lg bg-light" style="display:none;">
                                        <h6 class="font-weight-bold text-info small text-uppercase mb-3"><i class="fas fa-photo-video mr-2"></i> Media Requirements</h6>
                                        <div class="form-group mb-0">
                                            <label class="small font-weight-bold">Sample Media URL / Property *</label>
                                            <input type="text" name="exampleMedia" class="form-control form-control-modern" placeholder="HTTPS Link to a sample file">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <div class="d-flex justify-content-between">
                                            <label class="font-weight-600 small text-muted text-uppercase">Message Content (Body) *</label>
                                            <button type="button" class="btn btn-xs btn-link p-0 text-primary small" id="insertVarBtn"><i class="fas fa-plus-circle mr-1"></i> Insert {{n}}</button>
                                        </div>
                                        <textarea name="content" id="templateContentArea" class="form-control form-control-modern" rows="4" placeholder="Hello {{1}}, how are you?" required></textarea>
                                        <small class="text-muted">Use {{1}}, {{2}}, etc. for variables.</small>
                                    </div>

                                    <div class="bg-light p-4 rounded-lg mb-4 border">
                                        <h6 class="font-weight-bold small text-uppercase mb-3 text-primary"><i class="fas fa-magic mr-2"></i> Dynamic Variables Example</h6>
                                        <textarea name="example" class="form-control form-control-modern" rows="2" placeholder="Hello John, how are you?" required></textarea>
                                    </div>

                                    <!-- Buttons Section -->
                                    <div class="border rounded-lg p-4 mb-4">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="font-weight-bold small text-uppercase mb-0"><i class="fas fa-plus mr-2"></i> Interactive Buttons</h6>
                                            <button type="button" id="addButton" class="btn btn-sm btn-outline-primary rounded-pill px-3">+ Add</button>
                                        </div>
                                        <div id="buttonsContainer"></div>
                                        <input type="hidden" name="buttons" id="buttonsJson">
                                    </div>

                                    <div class="form-group mb-0">
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input" id="allowCategoryChange" name="allowTemplateCategoryChange" value="true">
                                            <label class="custom-control-label small font-weight-600" for="allowCategoryChange">
                                                Allow Meta to auto-update template category based on content
                                            </label>
                                        </div>
                                    </div>
                                    <div id="createError" class="alert alert-danger mt-3 small" style="display:none;"></div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-modern btn-outline-modern" data-dismiss="modal">Cancel</button>
                                    <button type="submit" id="submitTemplateBtn" class="btn btn-modern btn-primary-modern">Submit to Meta</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Template Modal -->
                <div class="modal fade" id="editTemplateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content border-top border-primary" style="border-top-width: 4px !important;">
                            <form id="editTemplateForm">
                                <input type="hidden" name="templateId">
                                <div class="modal-header">
                                    <h5 class="modal-title text-primary"><i class="fas fa-edit mr-2"></i> Modify Template</h5>
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <div id="editError" class="alert alert-danger small mb-4" style="display:none;"></div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Element Name</label>
                                            <input type="text" name="elementName" class="form-control form-control-modern bg-light" readonly>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Category</label>
                                            <select name="category" class="form-control form-control-modern" required>
                                                <option value="MARKETING">MARKETING</option>
                                                <option value="UTILITY">UTILITY</option>
                                                <option value="AUTHENTICATION">AUTHENTICATION</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label class="font-weight-600 small text-muted text-uppercase">Language</label>
                                            <input type="text" name="languageCode" class="form-control form-control-modern" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group mt-3">
                                        <label class="font-weight-600 small text-muted text-uppercase">Template Body</label>
                                        <textarea name="content" class="form-control form-control-modern" rows="4" required></textarea>
                                    </div>
                                    
                                    <!-- Buttons for Edit -->
                                    <div class="border rounded-lg p-4 mb-4 bg-light">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="font-weight-bold small text-uppercase mb-0 text-primary">Template Buttons</h6>
                                            <button type="button" id="addEditButton" class="btn btn-sm btn-primary py-1 px-3 rounded-pill">+ Add</button>
                                        </div>
                                        <div id="editButtonsContainer"></div>
                                        <input type="hidden" name="buttons" id="editButtonsJson">
                                    </div>

                                    <div class="form-group mb-0">
                                        <label class="font-weight-600 small text-muted text-uppercase">Sample Content</label>
                                        <textarea name="example" class="form-control form-control-modern" rows="2" required></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-modern btn-outline-modern" data-dismiss="modal">Discard</button>
                                    <button type="submit" id="submitEditBtn" class="btn btn-modern btn-primary-modern">Update Template</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>

                <!-- Template Preview Modal -->
                <div class="modal fade" id="templateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="tName">Preview Template</h5>
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body">
                                <div id="tReasonAlert" class="alert alert-danger mb-4 rounded-lg d-flex align-items-center" style="display:none;">
                                    <i class="fas fa-exclamation-triangle mr-3 fa-lg"></i>
                                    <div>
                                        <strong class="small text-uppercase">Meta Rejection Reason:</strong><br>
                                        <span id="tReason" class="small"></span>
                                    </div>
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-sm-3 mb-2">
                                        <div class="small text-muted text-uppercase font-weight-600 mb-1">Category</div>
                                        <div id="tCategory" class="font-weight-bold"></div>
                                    </div>
                                    <div class="col-sm-3 mb-2">
                                        <div class="small text-muted text-uppercase font-weight-600 mb-1">Status</div>
                                        <div id="tStatus"></div>
                                    </div>
                                    <div class="col-sm-3 mb-2">
                                        <div class="small text-muted text-uppercase font-weight-600 mb-1">Media</div>
                                        <div id="tType" class="font-weight-bold"></div>
                                    </div>
                                    <div class="col-sm-3 mb-2">
                                        <div class="small text-muted text-uppercase font-weight-600 mb-1">Language</div>
                                        <div id="tLang" class="font-weight-bold"></div>
                                    </div>
                                </div>

                                <div id="tMediaSection" class="mb-4 text-center p-3 bg-light rounded-lg border" style="display:none;">
                                    <div id="tMediaPreview"></div>
                                </div>

                                <div class="message-preview-bubble mb-4">
                                    <div class="small text-muted text-uppercase font-weight-600 mb-2">Message Content</div>
                                    <pre id="tData" class="p-4 bg-white border rounded-lg shadow-sm mb-0" style="white-space: pre-wrap; font-family: inherit; font-size: 0.95rem; line-height: 1.5;"></pre>
                                </div>

                                <div id="tButtonsSection" class="mt-4" style="display:none;">
                                    <div class="small text-muted text-uppercase font-weight-600 mb-2">Interactive Buttons</div>
                                    <div id="tButtonsList" class="d-flex flex-wrap" style="gap: 8px;"></div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-modern btn-primary-modern" data-dismiss="modal">Close Preview</button>
                            </div>
                        </div>
                    </div>
                </div>

            <div id="toastContainer"></div>
            <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
            <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
            <script>
                // Global Notification Helpers
                window.showToast = function(msg, type, duration) {
                    type = type || 'info';
                    duration = duration || 4000;
                    var icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
                    var toast = $('<div class="gup-toast ' + type + '"><i class="fas ' + icon + '"></i><span>' + msg + '</span></div>');
                    $('#toastContainer').append(toast);
                    setTimeout(function(){ 
                        toast.css({'opacity': '0', 'transform': 'translateX(20px)'}); 
                        setTimeout(function(){ toast.remove(); }, 500); 
                    }, duration);
                };

                window.showApiResponse = function(msg, type) {
                    var banner = $('#apiResponseBanner');
                    var alert = $('#apiResponseAlert');
                    alert.removeClass('alert-success alert-danger alert-info');
                    var bsType = type === 'success' ? 'alert-success' : (type === 'error' ? 'alert-danger' : 'alert-info');
                    alert.addClass(bsType);
                    $('#apiResponseTitle').html(msg);
                    banner.fadeIn();
                    // Scroll to banner if not in view
                    if (!isInViewport(banner[0])) {
                        $('html, body').animate({ scrollTop: banner.offset().top - 120 }, 500);
                    }
                };

                function isInViewport(element) {
                    if (!element) return true;
                    var rect = element.getBoundingClientRect();
                    return (rect.top >= 0 && rect.left >= 0 && rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) && rect.right <= (window.innerWidth || document.documentElement.clientWidth));
                }

                // LocalStorage Shim for Bitrix24 iframe security restrictions
                (function() {
                    try {
                        localStorage.getItem('test');
                    } catch (e) {
                        console.warn("LocalStorage access denied and shimmed for compatibility.");
                        var storage = {};
                        Object.defineProperty(window, 'localStorage', {
                            value: {
                                getItem: function(k) { return storage[k] || null; },
                                setItem: function(k, v) { storage[k] = v; },
                                removeItem: function(k) { delete storage[k]; },
                                clear: function() { storage = {}; },
                                length: Object.keys(storage).length,
                                key: function(i) { return Object.keys(storage)[i] || null; }
                            },
                            configurable: true,
                            enumerable: true,
                            writable: true
                        });
                    }
                })();

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
                            var errorMsg = 'Request failed (HTTP ' + xhr.status + ').';
                            if (xhr.responseText) {
                                try {
                                    var errObj = JSON.parse(xhr.responseText);
                                    errorMsg = errObj.message || errObj.error || errorMsg;
                                } catch(e) {
                                    errorMsg = xhr.responseText.substring(0, 100);
                                }
                            }
                            $('#createError').html('<strong>Error:</strong> ' + errorMsg).show();
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
                                        var statusPill = 'status-pill pending';
                                        if (t.status === 'APPROVED') statusPill = 'status-pill approved';
                                        if (t.status === 'REJECTED' || t.status === 'FAILED') statusPill = 'status-pill rejected';
                                        
                                        var reasonHtml = t.reason ? '<div class="small text-danger mt-1" style="max-width:200px"><i>' + t.reason + '</i></div>' : '';
                                        
                                        html += '<tr>';
                                        html += '<td><div class="font-weight-bold text-primary">' + t.elementName + '</div><div class="small text-muted">' + t.templateType + '</div></td>';
                                        html += '<td><span class="badge badge-light px-2 py-1">' + t.category + '</span></td>';
                                        html += '<td><span class="text-uppercase font-weight-600 small">' + t.languageCode + '</span></td>';
                                        html += '<td><span class="' + statusPill + '">' + t.status + '</span>' + reasonHtml + '</td>';
                                        html += '<td class="text-right"><div class="btn-group">';
                                        html += '<button class="btn btn-sm btn-outline-info rounded-circle mr-2 px-2 view-btn" data-json=\'' + JSON.stringify(t).replace(/'/g, "&apos;") + '\' title="View"><i class="fas fa-eye"></i></button>';
                                        html += '<button class="btn btn-sm btn-outline-primary rounded-circle mr-2 px-2 edit-btn" data-json=\'' + JSON.stringify(t).replace(/'/g, "&apos;") + '\' title="Edit"><i class="fas fa-edit"></i></button>';
                                        html += '<button class="btn btn-sm btn-outline-danger rounded-circle px-2 delete-btn" data-name="' + t.elementName + '" title="Delete"><i class="fas fa-trash"></i></button>';
                                        html += '</div></td></tr>';
                                    });
                                    window.allTemplatesData = response.templates;
                                } else {
                                    html = '<tr><td colspan="5" class="text-center py-5 text-muted">No templates available. Create one to get started!</td></tr>';
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
                        
                        var currentText = $('#campaignNumbersArea').val();
                        var existing = currentText.split('\n').filter(Boolean);
                        var combined = existing.concat(selected);
                        var unique = combined.filter(function(item, pos) { return combined.indexOf(item) === pos; });
                        
                        $('#campaignNumbersArea').val(unique.join('\n')).trigger('input');
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

                        // --- NEW: Template variable detection ---
                        detectTemplateVariables(selectedTemplate);
                    });

                    function detectTemplateVariables(template) {
                        if (!template || !template.content) {
                            $('#templateVariablesSection').hide();
                            return;
                        }

                        var content = template.content;
                        var matches = content.match(/\{\{\d+\}\}/g);
                        if (!matches) {
                            $('#templateVariablesSection').hide();
                            return;
                        }

                        // Get unique variables and sort them
                        var vars = [...new Set(matches)].sort((a, b) => {
                            var na = parseInt(a.replace(/[^\d]/g, ''));
                            var nb = parseInt(b.replace(/[^\d]/g, ''));
                            return na - nb;
                        });

                        var html = '';
                        vars.forEach(v => {
                            var num = v.replace(/[^\d]/g, '');
                            html += `<div class="form-group row align-items-center mb-3">
                                <label class="col-sm-3 col-form-label font-weight-bold">Variable ${v}</label>
                                <div class="col-sm-9">
                                    <div class="input-group">
                                        <select class="form-control var-mapping-type" data-var="${num}" style="max-width: 130px;">
                                            <option value="static">Static</option>
                                            <option value="csv" ${window.currentCsvHeaders ? '' : 'disabled'}>CSV Col</option>
                                        </select>
                                        <input type="text" class="form-control var-static-val" data-var="${num}" placeholder="Enter static value">
                                        <select class="form-control var-csv-col" data-var="${num}" style="display:none;">
                                            <option value="">-- Choose Column --</option>
                                            ${(window.currentCsvHeaders || []).map(h => `<option value="${h}">${h}</option>`).join('')}
                                        </select>
                                    </div>
                                </div>
                            </div>`;
                        });

                        $('#variableMappingList').html(html);
                        $('#templateVariablesSection').slideDown();
                    }

                    $(document).on('change', '.var-mapping-type', function() {
                        var varNum = $(this).data('var');
                        var type = $(this).val();
                        var parent = $(this).closest('.input-group');
                        if (type === 'static') {
                            parent.find('.var-static-val').show();
                            parent.find('.var-csv-col').hide();
                        } else {
                            parent.find('.var-static-val').hide();
                            parent.find('.var-csv-col').show();
                        }
                    });

                    $('#insertVarBtn').on('click', function() {
                        var area = $('#templateContentArea')[0];
                        var text = area.value;
                        var matches = text.match(/\{\{(\d+)\}\}/g);
                        var nextNum = 1;
                        if (matches) {
                            var nums = matches.map(m => parseInt(m.replace(/[^\d]/g, '')));
                            nextNum = Math.max(...nums) + 1;
                        }
                        var varStr = '{{' + nextNum + '}}';
                        var start = area.selectionStart;
                        var end = area.selectionEnd;
                        area.value = text.substring(0, start) + varStr + text.substring(end);
                        area.focus();
                        area.selectionStart = area.selectionEnd = start + varStr.length;
                    });

                    $('#campaignNumbersArea').on('input', function() {
                        var val = $(this).val();
                        var lines = val.split('\n').filter(Boolean).map(function(s){ return s.replace(/[^0-9]/g, ''); }).filter(Boolean);
                        var unique = lines.filter(function(item, pos) { return lines.indexOf(item) === pos; });
                        $('#campaignNumberCount').text(unique.length + ' unique numbers');
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

                        // Collect variable mappings
                        var varMappings = [];
                        $('.var-mapping-type').each(function() {
                            var num = $(this).data('var');
                            var type = $(this).val();
                            var mapping = { num: num, type: type };
                            if (type === 'static') {
                                mapping.value = $(`.var-static-val[data-var="${num}"]`).val();
                            } else {
                                mapping.value = $(`.var-csv-col[data-var="${num}"]`).val();
                            }
                            varMappings.push(mapping);
                        });
                        if (varMappings.length > 0) {
                            formData.push({name: 'varMappings', value: JSON.stringify(varMappings)});
                        }

                        // Send CSV rows if available
                        if (window.currentCsvRows && window.currentCsvRows.length > 0) {
                            formData.push({name: 'csvData', value: JSON.stringify(window.currentCsvRows)});
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

                    // --- CSV Upload Logic ---
                    $('#uploadCsvBtn').on('click', function() {
                        $('#bulkCsvInput').click();
                    });

                    $('#bulkCsvInput').on('change', function(e) {
                        var file = e.target.files[0];
                        if (!file) return;

                        $('#csvBtnIcon').hide();
                        $('#csvBtnSpinner').show();
                        $('#uploadCsvBtn').prop('disabled', true);

                        var reader = new FileReader();
                        reader.onload = function(e) {
                            var contents = e.target.result;
                            var lines = contents.split(/\r?\n/).filter(l => l.trim());
                            if (lines.length < 1) {
                                resetCsvBtn();
                                return;
                            }

                            // Use a simple split but handle potential quotes in a basic way
                            function parseCsvLine(line) {
                                return line.split(',').map(c => c.replace(/^"(.*)"$/, '$1').trim());
                            }

                            var headers = parseCsvLine(lines[0]);
                            var phoneIdx = headers.findIndex(h => h.toLowerCase() === 'phone');

                            if (phoneIdx === -1) {
                                alert('Error: No column named "phone" found in CSV.');
                                resetCsvBtn();
                                return;
                            }

                            window.currentCsvHeaders = headers;
                            window.currentCsvRows = [];
                            var phoneNumbers = [];

                            for (var i = 1; i < lines.length; i++) {
                                var cols = parseCsvLine(lines[i]);
                                if (cols[phoneIdx]) {
                                    var cleaned = cols[phoneIdx].replace(/[^\d+]/g, '').trim();
                                    if (cleaned) {
                                        phoneNumbers.push(cleaned);
                                        var rowData = {};
                                        headers.forEach((h, idx) => {
                                            rowData[h] = cols[idx] || '';
                                        });
                                        window.currentCsvRows.push(rowData);
                                    }
                                }
                            }

                            if (phoneNumbers.length > 0) {
                                var currentVal = $('#campaignNumbersArea').val().trim();
                                var newVal = (currentVal ? currentVal + "\n" : "") + phoneNumbers.join("\n");
                                $('#campaignNumbersArea').val(newVal).trigger('input');
                                
                                // Update mapping UI if variables exist
                                var templateId = $('#campaignTemplateSelect').val();
                                var selectedTemplate = (window.allTemplatesData || []).find(t => (t.id || t.templateId || t.externalId) === templateId);
                                if (selectedTemplate) detectTemplateVariables(selectedTemplate);
                                
                                showToast('Imported ' + phoneNumbers.length + ' rows from CSV', 'success');
                            } else {
                                alert('No valid numbers found in the "phone" column.');
                            }
                            
                            resetCsvBtn();
                        };
                        reader.readAsText(file);
                    });

                    function resetCsvBtn() {
                        $('#csvBtnIcon').show();
                        $('#csvBtnSpinner').hide();
                        $('#uploadCsvBtn').prop('disabled', false);
                        $('#bulkCsvInput').val('');
                    }

                    // --- Campaign Analysis Logic ---
                    function loadCampaignAnalysis() {
                        $('#campaignAnalysisList').html('<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm text-secondary"></div> Loading...</td></tr>');
                        $.ajax({
                            url: 'get_campaign_analysis.php?' + new Date().getTime(),
                            method: 'GET',
                            success: function(res) {
                                if (res.status === 'success' && res.data && res.data.length > 0) {
                                        var html = '<div class="table-responsive" style="max-height: 400px; overflow-y: auto;">' +
                                            '<table class="table table-sm table-modern table-hover align-middle">' +
                                            '<thead><tr>' +
                                            '<th>Executed</th>' +
                                            '<th>Template</th>' +
                                            '<th>Targets</th>' +
                                            '<th>Sent</th>' +
                                            '<th>Delivered</th>' +
                                            '<th>Read</th>' +
                                            '<th>Failed</th>' +
                                            '<th>Manage</th>' +
                                            '</tr></thead><tbody>';

                                        res.data.forEach(function(job) {
                                            var d = job.delivered || 0;
                                            var r = job.read || 0;
                                            var f = (job.failed || 0) + (job.webhook_failed || 0);
                                            var sent = job.success || 0;
                                            html += '<tr>' +
                                                    '<td class="small">' + (job.created_at || '-') + '</td>' +
                                                    '<td><div class="font-weight-bold text-primary">' + (job.template_name || 'Unknown') + '</div><div class="small text-muted">' + job.job_id + '</div></td>' +
                                                    '<td class="font-weight-bold">' + job.total + '</td>' +
                                                    '<td>' + sent + '</td>' +
                                                    '<td><span class="text-success font-weight-bold">' + d + '</span></td>' +
                                                    '<td><span class="text-primary font-weight-bold">' + r + '</span></td>' +
                                                    '<td><span class="text-danger font-weight-bold">' + f + '</span></td>' +
                                                    '<td class="text-right"><button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="viewCampaignDetails(\'' + job.job_id + '\')"><i class="fas fa-search-plus mr-1"></i> Details</button></td>' +
                                                    '</tr>';
                                        });
                                        html += '</tbody></table></div>';
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
                                        var statusPill = 'status-pill pending';
                                        if (item.event === 'APPROVED') statusPill = 'status-pill approved';
                                        if (item.event === 'FAILED' || item.event === 'REJECTED') statusPill = 'status-pill rejected';

                                        html += '<tr>' +
                                                '<td class="small">' + (item.timestamp || '-') + '</td>' +
                                                '<td><strong>' + (item.template_name || '-') + '</strong></td>' +
                                                '<td><span class="' + statusPill + '">' + (item.event || 'UNKNOWN') + '</span></td>' +
                                                '<td><span class="text-uppercase small">' + (item.language || '-') + '</span></td>' +
                                                '<td><div class="small text-muted" style="max-width:200px">' + (item.reason || '-') + '</div></td>' +
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
                                            var statusPill = 'status-pill pending';
                                            if (t.status === 'sent' || t.status === 'success') statusPill = 'status-pill pending'; // essentially in progress
                                            if (t.status === 'delivered') statusPill = 'status-pill approved'; // using approved green for delivery
                                            if (t.status === 'read') statusPill = 'status-pill approved'; 
                                            if (t.status === 'failed' || t.status === 'webhook_failed') statusPill = 'status-pill rejected';

                                            html += '<tr>' +
                                                    '<td class="font-weight-bold">' + t.phone + '</td>' +
                                                    '<td><span class="' + statusPill + '">' + t.status.toUpperCase() + '</span></td>' +
                                                    '<td><div class="small text-danger">' + (t.error || '-') + '</div></td>' +
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