<?php
declare(strict_types=1);

// Suppress display errors to keep JSON output clean
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

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

try {
    $sessionManager = new SessionManager();
    $storedAuth = $sessionManager->getAuth();
    $configFile = __DIR__ . '/../config.php';
    $whatsappConfig = file_exists($configFile) ? require $configFile : [];

    if (!$storedAuth) {
        echo json_encode(['error' => 'Unauthorized - no session found']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    // Accept new format: { mode, user_ids, department_ids }
    $mode = $input['mode'] ?? 'user';
    $userIds = $input['user_ids'] ?? [];
    $departmentIds = $input['department_ids'] ?? [];

    if (!in_array($mode, ['user', 'department'])) {
        echo json_encode(['error' => 'Invalid mode. Must be "user" or "department"']);
        exit;
    }

    // Initialize SDK (same pattern as usage_report.php)
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

    // 1. Verify access - exactly as in usage_report.php
    $currentUser = $b24Service->getUserScope()->user()->current()->user();
    $userEmail = $currentUser->EMAIL ?? '';

    if (!str_ends_with(strtolower($userEmail), '@keenenter.com')) {
        echo json_encode(['error' => 'Access Restricted: Only @keenenter.com users. Current: ' . ($userEmail ?: 'Unknown')]);
        exit;
    }

    // 2. Save access config
    $varDir = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
    
    // Ensure directory exists
    if (!is_dir($varDir)) {
        @mkdir($varDir, 0775, true);
    }

    $allowedUsersFile = $varDir . '/allowed_users.json';

    $configData = [
        'mode' => $mode,
        'user_ids' => is_array($userIds) ? $userIds : [],
        'department_ids' => is_array($departmentIds) ? $departmentIds : [],
    ];

    if (file_put_contents($allowedUsersFile, json_encode($configData, JSON_PRETTY_PRINT))) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to write to file: ' . $allowedUsersFile]);
    }

} catch (\Throwable $e) {
    echo json_encode(['error' => 'Server Error: ' . $e->getMessage()]);
}
