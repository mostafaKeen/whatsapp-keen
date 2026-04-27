<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Dubai');

use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Endpoints;
use Bitrix24\SDK\Core\Batch;
use Bitrix24\SDK\Core\BulkItemsReader\BulkItemsReaderBuilder;
use Psr\Log\NullLogger;

// 1. Configuration & Auth
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    die("Configuration file missing. Please set up the app first.");
}
$whatsappConfig = require $configFile;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/SessionManager.php';

$sessionManager = new SessionManager();
$storedAuth = $sessionManager->getAuth();
$isAuthorized = false;
$userEmail = '';
$accessError = null;

if ($storedAuth) {
    try {
        $appProfile = ApplicationProfile::initFromArray([
            'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'] ?? '',
            'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'] ?? '',
            'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_SCOPE'] ?? '',
        ]);

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
        
        // Call user.current
        $currentUser = $b24Service->getMainScope()->userCurrent()->getUser();
        $userEmail = $currentUser->EMAIL ?? '';
        
        if (str_ends_with(strtolower($userEmail), '@keenenter.com')) {
            $isAuthorized = true;
        } else {
            $accessError = "Access Restricted: This page is only available for @keenenter.com users. Current user: " . ($userEmail ?: 'Unknown');
        }
    } catch (\Exception $e) {
        $accessError = "Authentication Error: " . $e->getMessage();
    }
} else {
    $accessError = "Session Expired: Please reopen the application from Bitrix24.";
}

if (!$isAuthorized) {
    // If not authorized, we will skip the Gupshup logic but still show the header/error in HTML
    $appId = '';
    $apiToken = '';
} else {
    $appId = $whatsappConfig['gupshup_app_id'] ?? '';
    $apiToken = $whatsappConfig['gupshup_api_token'] ?? '';
}

// 2. Handle Date Range
$fromDate = $_GET['from'] ?? date('Y-m-01'); // Default to start of current month
$toDate = $_GET['to'] ?? date('Y-m-d');

$error = null;
$usageData = [];
$totals = [
    'fees' => 0,
    'messages' => 0,
    'marketing' => 0,
    'utility' => 0,
    'authentication' => 0,
    'service' => 0,
    'currency' => 'USD'
];

if ($appId && $apiToken) {
    $url = "https://partner.gupshup.io/partner/app/{$appId}/usage?from=" . urlencode($fromDate) . "&to=" . urlencode($toDate);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiToken,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        $error = "CURL Error: " . $curlError;
    } elseif ($httpCode !== 200) {
        $error = "API Error (HTTP $httpCode): " . $response;
    } else {
        $decoded = json_decode((string)$response, true);
        if ($decoded && isset($decoded['status']) && $decoded['status'] === 'success') {
            $usageData = $decoded['partnerAppUsageList'] ?? [];
            
            // Calculate Totals
            foreach ($usageData as $day) {
                $totals['fees'] += $day['totalFees'] ?? 0;
                $totals['messages'] += $day['totalMsg'] ?? 0;
                $totals['marketing'] += $day['marketing'] ?? 0;
                $totals['utility'] += $day['utility'] ?? 0;
                $totals['authentication'] += $day['authentication'] ?? 0;
                $totals['service'] += $day['service'] ?? 0;
                $totals['currency'] = $day['currency'] ?? 'USD';
            }
        } else {
            $error = "Failed to parse API response or status not success.";
        }
    }
} else {
    $error = "Gupshup App ID or API Token is not configured.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage & Billing | KEEN Nexus</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
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
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 20px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #f1fdf4 100%);
            color: var(--text-main);
            min-height: 100vh;
            padding: 2rem 0;
        }

        .container { max-width: 1100px; }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            padding: 2.5rem;
            margin-bottom: 2rem;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
        }

        .app-logo {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .metric-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid var(--border);
            transition: transform 0.2s ease;
        }

        .metric-card:hover { transform: translateY(-4px); }

        .metric-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            letter-spacing: 0.05em;
        }

        .metric-value {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .btn-modern {
            padding: 0.6rem 1.2rem;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-primary-modern { background: var(--primary); color: white; border: none; }
        .btn-primary-modern:hover { background: var(--primary-dark); color: white; }
        
        .btn-outline-modern { 
            background: transparent; 
            border: 1px solid var(--border); 
            color: var(--text-main);
        }

        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .table-modern th {
            text-transform: uppercase;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-muted);
            padding: 0.5rem 1.25rem;
            border: none;
        }

        .table-modern tbody tr {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .table-modern td {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .table-modern td:first-child { border-left: 1px solid var(--border); border-radius: 12px 0 0 12px; }
        .table-modern td:last-child { border-right: 1px solid var(--border); border-radius: 0 12px 12px 0; }

        .form-control-modern {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .category-pill {
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <a href="index.php?<?= http_build_query($_GET) ?>" class="app-logo">
                <i class="fab fa-whatsapp"></i>
                <span>KEEN Nexus</span>
            </a>
            <a href="index.php?<?= http_build_query($_GET) ?>" class="btn btn-modern btn-outline-modern">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <div class="glass-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">Usage & Billing Report</h2>
                    <p class="text-muted small mb-0">Detailed insights into your WhatsApp costs and message volume.</p>
                </div>
                
                <form class="form-inline" method="GET">
                    <?php foreach ($_GET as $key => $value): ?>
                        <?php if ($key !== 'from' && $key !== 'to'): ?>
                            <input type="hidden" name="<?= htmlspecialchars((string)$key) ?>" value="<?= htmlspecialchars((string)$value) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="form-group mr-2">
                        <label class="sr-only">From</label>
                        <input type="date" name="from" class="form-control form-control-modern" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="form-group mr-2">
                        <label class="sr-only">To</label>
                        <input type="date" name="to" class="form-control form-control-modern" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <button type="submit" class="btn btn-modern btn-primary-modern">
                        <i class="fas fa-sync-alt"></i> Update
                    </button>
                </form>
            </div>

            <?php if ($accessError): ?>
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-lock fa-4x text-danger opacity-25"></i>
                    </div>
                    <h3 class="font-weight-700 text-danger mb-3">Restricted Access</h3>
                    <p class="text-muted mx-auto mb-4" style="max-width: 500px;">
                        <?= htmlspecialchars($accessError) ?>
                    </p>
                    <a href="index.php?<?= http_build_query($_GET) ?>" class="btn btn-modern btn-outline-modern">
                        <i class="fas fa-home"></i> Back to Dashboard
                    </a>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger rounded-lg">
                    <i class="fas fa-exclamation-triangle mr-2"></i> <?= $error ?>
                </div>
            <?php endif; ?>

            <?php if ($isAuthorized): ?>
            <!-- Metrics -->
            <div class="metric-grid">
                <div class="metric-card shadow-sm">
                    <div class="metric-label">Total Spent</div>
                    <div class="metric-value text-primary"><?= number_format($totals['fees'], 3) ?> <small><?= $totals['currency'] ?></small></div>
                </div>
                <div class="metric-card shadow-sm">
                    <div class="metric-label">Total Messages</div>
                    <div class="metric-value"><?= number_format($totals['messages']) ?></div>
                </div>
                <div class="metric-card shadow-sm">
                    <div class="metric-label">Avg. Cost/Msg</div>
                    <div class="metric-value text-info">
                        <?= $totals['messages'] > 0 ? number_format($totals['fees'] / $totals['messages'], 4) : '0.000' ?>
                    </div>
                </div>
            </div>

            <!-- Category Breakdown -->
            <h5 class="mb-3 font-weight-700">Message Breakdown</h5>
            <div class="row mb-5">
                <div class="col-md-3">
                    <div class="p-3 bg-white border rounded-lg text-center">
                        <div class="text-muted small font-weight-700 text-uppercase mb-1">Marketing</div>
                        <div class="h4 mb-0 font-weight-bold"><?= number_format($totals['marketing']) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-white border rounded-lg text-center">
                        <div class="text-muted small font-weight-700 text-uppercase mb-1">Utility</div>
                        <div class="h4 mb-0 font-weight-bold"><?= number_format($totals['utility']) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-white border rounded-lg text-center">
                        <div class="text-muted small font-weight-700 text-uppercase mb-1">Auth</div>
                        <div class="h4 mb-0 font-weight-bold"><?= number_format($totals['authentication']) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-white border rounded-lg text-center">
                        <div class="text-muted small font-weight-700 text-uppercase mb-1">Service</div>
                        <div class="h4 mb-0 font-weight-bold"><?= number_format($totals['service']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Details Table -->
            <h5 class="mb-3 font-weight-700">Daily Details</h5>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Messages</th>
                            <th>Marketing</th>
                            <th>Utility</th>
                            <th>Auth</th>
                            <th>Fees (<?= $totals['currency'] ?>)</th>
                            <th class="text-right">Cumulative</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usageData)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No data available for the selected range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usageData as $day): ?>
                                <tr>
                                    <td class="font-weight-600"><?= $day['date'] ?></td>
                                    <td><?= number_format($day['totalMsg']) ?></td>
                                    <td><span class="category-pill"><?= number_format($day['marketing']) ?></span></td>
                                    <td><span class="category-pill"><?= number_format($day['utility']) ?></span></td>
                                    <td><span class="category-pill"><?= number_format($day['authentication']) ?></span></td>
                                    <td class="text-danger font-weight-700"><?= number_format($day['totalFees'], 3) ?></td>
                                    <td class="text-right text-muted"><?= number_format($day['cumulativeBill'], 3) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; // End isAuthorized ?>
        </div>
    </div>
</body>
</html>
