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

    // 2. Load allowed users from file
    $varDir = $whatsappConfig['var_dir'] ?? (dirname(__DIR__, 2) . '/var');
    $allowedUsersFile = $varDir . '/allowed_users.json';
    $fileExists = file_exists($allowedUsersFile);
    $allowedUsers = $fileExists ? (json_decode(file_get_contents($allowedUsersFile), true) ?: []) : [];

    // 3. Fetch all active users via cURL (most reliable, avoids SDK abstraction issues)
    $accessToken = $storedAuth['AUTH_ID'];
    $apiUrl = rtrim($domain, '/') . '/rest/user.get.json';
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'auth' => $accessToken,
        'FILTER' => ['ACTIVE' => true],
    ]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['error' => 'cURL error fetching users: ' . $curlError]);
        exit;
    }

    $decoded = json_decode($response, true);
    $allUsers = [];

    if (isset($decoded['result']) && is_array($decoded['result'])) {
        foreach ($decoded['result'] as $user) {
            $allUsers[] = [
                'ID' => $user['ID'],
                'NAME' => trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? '')),
                'EMAIL' => $user['EMAIL'] ?? '',
                'PHOTO' => $user['PERSONAL_PHOTO'] ?? ''
            ];
        }
    }

    // If file doesn't exist, treat everyone as allowed
    if (!$fileExists) {
        $allowedUsers = array_column($allUsers, 'ID');
    }

    echo json_encode([
        'allowed' => $allowedUsers,
        'all' => $allUsers
    ]);

} catch (\Throwable $e) {
    echo json_encode(['error' => 'Server Error: ' . $e->getMessage()]);
}
