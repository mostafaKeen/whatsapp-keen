<?php
declare(strict_types=1);

require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Endpoints;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Batch;
use Bitrix24\SDK\Core\BulkItemsReader\BulkItemsReaderBuilder;
use Psr\Log\NullLogger;

$sessionManager = new SessionManager();
$storedAuth = $sessionManager->getAuth();
$configFile = __DIR__ . '/../config.php';
$whatsappConfig = file_exists($configFile) ? require $configFile : [];

if (!$storedAuth) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Initialize SDK
$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'] ?? '',
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'] ?? '',
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_SCOPE'] ?? '',
]);

$authToken = new AuthToken($storedAuth['AUTH_ID'], $storedAuth['REFRESH_ID'], (int)$storedAuth['AUTH_EXPIRES']);
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

try {
    // Enable error output for debugging (exactly as in usage_report.php)
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);

    // 1. Verify access (@keenenter.com check exactly as in usage_report.php)
    $currentUser = $b24Service->getUserScope()->user()->current()->user();
    $userEmail = $currentUser->EMAIL ?? '';
    
    $isAuthorized = false;
    if (str_ends_with(strtolower($userEmail), '@keenenter.com')) {
        $isAuthorized = true;
    }

    if (!$isAuthorized) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Access Restricted: This page is only available for @keenenter.com users. Current user: ' . ($userEmail ?: 'Unknown')]);
        exit;
    }

    // 2. Load allowed users
    $varDir = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
    $allowedUsersFile = $varDir . '/allowed_users.json';
    $allowedUsers = file_exists($allowedUsersFile) ? json_decode(file_get_contents($allowedUsersFile), true) : [];

    // 3. Fetch all active users for the selection list
    $allUsers = [];
    $usersResult = $b24Service->getUserScope()->user()->get(['ACTIVE' => 'Y']);
    
    // The SDK's getUsers() might not be directly available on the result object depending on version
    // Let's use the core call if needed, but usually it's there.
    // Actually, I'll use raw core call for users to be safe and get exactly what I need.
    $rawUsers = $core->call('user.get', ['FILTER' => ['ACTIVE' => 'Y']]);
    
    if (isset($rawUsers['result'])) {
        foreach ($rawUsers['result'] as $user) {
            $allUsers[] = [
                'ID' => $user['ID'],
                'NAME' => ($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? ''),
                'EMAIL' => $user['EMAIL'] ?? '',
                'PHOTO' => $user['PERSONAL_PHOTO'] ?? ''
            ];
        }
    }

    // If file doesn't exist, treat everyone as allowed
    if (!file_exists($allowedUsersFile)) {
        $allowedUsers = array_column($allUsers, 'ID');
    }

    header('Content-Type: application/json');
    echo json_encode([
        'allowed' => $allowedUsers,
        'all' => $allUsers
    ]);

} catch (\Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
