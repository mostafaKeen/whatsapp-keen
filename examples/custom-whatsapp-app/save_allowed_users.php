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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userIds = $input['user_ids'] ?? [];

if (!is_array($userIds)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid input']);
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
    // 1. Verify access (Admin or @keenenter.com)
    $currentUserResult = $b24Service->getUserScope()->user()->current();
    $currentUser = $currentUserResult->user();
    
    $isAdmin = (bool)($currentUser->ADMIN ?? false);
    $email = $currentUser->EMAIL ?? '';
    $isKeen = str_ends_with(strtolower($email), '@keenenter.com');

    if (!$isAdmin && !$isKeen) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    // 2. Save allowed users
    $varDir = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
    $allowedUsersFile = $varDir . '/allowed_users.json';
    
    if (file_put_contents($allowedUsersFile, json_encode($userIds, JSON_PRETTY_PRINT))) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to write to file']);
    }

} catch (\Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
