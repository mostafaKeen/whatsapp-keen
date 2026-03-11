<?php
$whatsappConfig = require __DIR__ . '/../config.php';

define('C_REST_CLIENT_ID', $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID']);
define('C_REST_CLIENT_SECRET', $whatsappConfig['BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET']);

// define('C_REST_LOG_TYPE_DUMP', true); // Optional: logs save var_export
define('C_REST_LOGS_DIR', __DIR__ . '/logs/'); 

// Gupshup settings (passed through to other scripts if needed)
define('GUPSHUP_APP_ID', $whatsappConfig['gupshup_app_id']);
define('GUPSHUP_API_TOKEN', $whatsappConfig['gupshup_api_token']);
