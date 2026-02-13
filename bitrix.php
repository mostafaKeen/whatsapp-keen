<?php
// bitrix.php
// Common helpers: load config.json, callBitrix(), token refresh, logging.

define('CONFIG_FILE', __DIR__ . '/config.json');

// === EDIT THIS: put your application secret (client_secret) here if you want auto-refresh ===
define('CLIENT_SECRET', 'oiixc69ztRFMnM66PtEXXH9LKP3uY0axkPW33r74Xth9sHpHD9'); // <- replace if different
// ==========================================================================

function loadConfig() {
    if (!file_exists(CONFIG_FILE)) {
        throw new Exception("config.json not found. Run install.php first (install the application).");
    }
    $c = json_decode(file_get_contents(CONFIG_FILE), true);
    if (!is_array($c)) throw new Exception("Invalid config.json");
    return $c;
}

function saveConfig($config) {
    file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
}

function logLine($text) {
    file_put_contents(__DIR__ . '/robodesk.log', date('[Y-m-d H:i:s] ') . $text . PHP_EOL, FILE_APPEND);
}

/**
 * Refresh access token using refresh_token flow.
 * Requires APP_SID in config and CLIENT_SECRET constant set.
 */
function refreshAccessToken(&$config) {
    if (empty($config['APP_SID'])) {
        throw new Exception("APP_SID missing in config.json, cannot refresh token.");
    }
    if (empty($config['REFRESH_ID'])) {
        throw new Exception("REFRESH_ID missing in config.json, cannot refresh token.");
    }
    if (!defined('CLIENT_SECRET') || CLIENT_SECRET === '') {
        throw new Exception("CLIENT_SECRET not set in bitrix.php; put your app key to enable refresh.");
    }

    // Bitrix OAuth token endpoint
    // Format: https://oauth.bitrix.info/oauth/token/?grant_type=refresh_token&client_id=APP_SID&client_secret=CLIENT_SECRET&refresh_token=REFRESH_ID
    $url = rtrim($config['SERVER_ENDPOINT'], '/') . '/oauth/token/?'
        . http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $config['APP_SID'],
            'client_secret' => CLIENT_SECRET,
            'refresh_token' => $config['REFRESH_ID']
        ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    logLine("refreshAccessToken HTTP={$http} err={$err} resp=" . substr($resp,0,300));

    if ($http >= 200 && $http < 300) {
        $r = json_decode($resp, true);
        if (isset($r['access_token'])) {
            // update config
            $config['AUTH_ID'] = $r['access_token'];
            if (isset($r['refresh_token'])) $config['REFRESH_ID'] = $r['refresh_token'];
            if (isset($r['expires_in'])) $config['AUTH_EXPIRES'] = $r['expires_in'];
            saveConfig($config);
            return true;
        }
    }
    return false;
}

/**
 * Call Bitrix REST API.
 * Automatically tries token refresh once on expired_token.
 */
function callBitrix($method, $params = [], $tries = 0) {
    $config = loadConfig();
    $domain = $config['DOMAIN'];
    $access_token = $config['AUTH_ID'];

    $url = "https://{$domain}/rest/{$method}";
    // append auth as query param
    $urlWithAuth = $url . '?auth=' . urlencode($access_token);

    $ch = curl_init($urlWithAuth);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $resultRaw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    logLine("callBitrix method={$method} http={$http} err={$err} params=" . substr(json_encode($params),0,500));

    if ($resultRaw === false) {
        return ['error' => 'curl_error', 'error_description' => $err];
    }

    $result = json_decode($resultRaw, true);
    if (!is_array($result)) {
        // non-json (rare)
        return ['error' => 'invalid_response', 'error_description' => $resultRaw];
    }

    // if token expired -> try refresh once
    if (isset($result['error']) && ($result['error'] === 'expired_token' || $result['error'] === 'AUTH_ERROR') && $tries === 0) {
        try {
            if (refreshAccessToken($config)) {
                // try again once
                return callBitrix($method, $params, 1);
            } else {
                return ['error' => 'refresh_failed', 'error_description' => 'Token refresh failed'];
            }
        } catch (Exception $e) {
            return ['error' => 'refresh_exception', 'error_description' => $e->getMessage()];
        }
    }

    return $result;
}
