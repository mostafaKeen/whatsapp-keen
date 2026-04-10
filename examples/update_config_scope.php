<?php
/**
 * update_config_scope.php
 * ------------------------------------------------------------------
 * Utility script to ensure config.php has the required scopes for
 * Message Service and Real-time updates.
 * Run this script to "warm up" the config before/during deployment.
 * ------------------------------------------------------------------
 */

$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
    die("Error: config.php not found at $configFile\n");
}

$content = file_get_contents($configFile);

$requiredScopes = ['messageservice', 'pull', 'pull_channel', 'crm', 'im', 'imconnector'];
$newScopeString = implode(',', $requiredScopes);

// Regex to find the scope line and replace its value
$pattern = "/('BITRIX24_PHP_SDK_APPLICATION_SCOPE'\s*=>\s*')[^']*(')/";

if (preg_match($pattern, $content, $matches)) {
    $updatedContent = preg_replace($pattern, "$1$newScopeString$2", $content);
    
    if ($updatedContent !== $content) {
        if (file_put_contents($configFile, $updatedContent)) {
            echo "Successfully updated config.php with scopes: $newScopeString\n";
        } else {
            echo "Error: Failed to write to config.php\n";
        }
    } else {
        echo "Config already has correct or overlapping scopes.\n";
    }
} else {
    echo "Warning: No BITRIX24_PHP_SDK_APPLICATION_SCOPE key found in config.php. Doing nothing.\n";
}
