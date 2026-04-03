<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once (__DIR__.'/crest.php');

$install_result = CRest::installApp();

// If Bitrix24 sends installation POST data, handle the placement binding immediately.
$is_installed = false;
$error_message = '';

// Construct the handler URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$path = str_replace('install.php', 'placement.php', $_SERVER['REQUEST_URI']);
// Remove query params if any
$path = explode('?', $path)[0];

$handlerBackUrl = $protocol . $host . $path;

if ($install_result['install'] === true) {
    
    $placements = ['CRM_LEAD_DETAIL_TAB', 'CRM_DEAL_DETAIL_TAB'];
    $binding_results = [];

    foreach ($placements as $pCode) {
        $res = CRest::call(
            'placement.bind',
            [
                'PLACEMENT' => $pCode,
                'HANDLER' => $handlerBackUrl,
                'TITLE' => 'whatsapp'
            ]
        );
        $binding_results[$pCode] = $res;
    }
    
    // Register Keen Nexus Open Channel Connector
    $connectorUrl = str_replace('placement.php', 'connector_setup.php', $handlerBackUrl);
    $messagingHandlerUrl = str_replace('placement.php', 'handler.php', $handlerBackUrl);
    
    $connectorRes = CRest::call(
        'imconnector.register',
        [
            'ID' => 'keen_nexus',
            'NAME' => 'Keen Nexus',
            'ICON' => [
                'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20version%3D%221.1%22%20id%3D%22Layer_1%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20x%3D%220px%22%20y%3D%220px%22%0A%09%20viewBox%3D%220%200%2070%2071%22%20style%3D%22enable-background%3Anew%200%200%2070%2071%3B%22%20xml%3Aspace%3D%22preserve%22%3E%0A%3Cpath%20fill%3D%22%2325D366%22%20class%3D%22st0%22%20d%3D%22M34.7%2C64c-11.6%2C0-22-7.1-26.3-17.8C4%2C35.4%2C6.4%2C23%2C14.5%2C14.7c8.1-8.2%2C20.4-10.7%2C31-6.2%0A%09c12.5%2C5.4%2C19.6%2C18.8%2C17%2C32.2C60%2C54%2C48.3%2C63.8%2C34.7%2C64L34.7%2C64z%20M27.8%2C29c0.8-0.9%2C0.8-2.3%2C0-3.2l-1-1.2h19.3c1-0.1%2C1.7-0.9%2C1.7-1.8%0A%09v-0.9c0-1-0.7-1.8-1.7-1.8H26.8l1.1-1.2c0.8-0.9%2C0.8-2.3%2C0-3.2c-0.4-0.4-0.9-0.7-1.5-0.7s-1.1%2C0.2-1.5%2C0.7l-4.6%2C5.1%0A%09c-0.8%2C0.9-0.8%2C2.3%2C0%2C3.2l4.6%2C5.1c0.4%2C0.4%2C0.9%2C0.7%2C1.5%2C0.7C26.9%2C29.6%2C27.4%2C29.4%2C27.8%2C29L27.8%2C29z%20M44%2C41c-0.5-0.6-1.3-0.8-2-0.6%0A%09c-0.7%2C0.2-1.3%2C0.9-1.5%2C1.6c-0.2%2C0.8%2C0%2C1.6%2C0.5%2C2.2l1%2C1.2H22.8c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h19.3l-1%2C1.2%0A%09c-0.5%2C0.6-0.7%2C1.4-0.5%2C2.2c0.2%2C0.8%2C0.7%2C1.4%2C1.5%2C1.6c0.7%2C0.2%2C1.5%2C0%2C2-0.6l4.6-5.1c0.8-0.9%2C0.8-2.3%2C0-3.2L44%2C41z%20M23.5%2C32.8%0A%09c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h23.4c1-0.1%2C1.7-0.9%2C1.7-1.8v-0.9c0-1-0.7-1.8-1.7-1.9L23.5%2C32.8L23.5%2C32.8z%22/%3E%0A%3C/svg%3E%0A',
                'COLOR' => '#e8f5e9',
                'SIZE' => '100%',
                'POSITION' => 'center',
            ],
            'ICON_DISABLED' => [
                'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20version%3D%221.1%22%20id%3D%22Layer_1%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20x%3D%220px%22%20y%3D%220px%22%0A%09%20viewBox%3D%220%200%2070%2071%22%20style%3D%22enable-background%3Anew%200%200%2070%2071%3B%22%20xml%3Aspace%3D%22preserve%22%3E%0A%3Cpath%20fill%3D%22%23cccccc%22%20class%3D%22st0%22%20d%3D%22M34.7%2C64c-11.6%2C0-22-7.1-26.3-17.8C4%2C35.4%2C6.4%2C23%2C14.5%2C14.7c8.1-8.2%2C20.4-10.7%2C31-6.2%0A%09c12.5%2C5.4%2C19.6%2C18.8%2C17%2C32.2C60%2C54%2C48.3%2C63.8%2C34.7%2C64L34.7%2C64z%20M27.8%2C29c0.8-0.9%2C0.8-2.3%2C0-3.2l-1-1.2h19.3c1-0.1%2C1.7-0.9%2C1.7-1.8%0A%09v-0.9c0-1-0.7-1.8-1.7-1.8H26.8l1.1-1.2c0.8-0.9%2C0.8-2.3%2C0-3.2c-0.4-0.4-0.9-0.7-1.5-0.7s-1.1%2C0.2-1.5%2C0.7l-4.6%2C5.1%0A%09c-0.8%2C0.9-0.8%2C2.3%2C0%2C3.2l4.6%2C5.1c0.4%2C0.4%2C0.9%2C0.7%2C1.5%2C0.7C26.9%2C29.6%2C27.4%2C29.4%2C27.8%2C29L27.8%2C29z%20M44%2C41c-0.5-0.6-1.3-0.8-2-0.6%0A%09c-0.7%2C0.2-1.3%2C0.9-1.5%2C1.6c-0.2%2C0.8%2C0%2C1.6%2C0.5%2C2.2l1%2C1.2H22.8c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h19.3l-1%2C1.2%0A%09c-0.5%2C0.6-0.7%2C1.4-0.5%2C2.2c0.2%2C0.8%2C0.7%2C1.4%2C1.5%2C1.6c0.7%2C0.2%2C1.5%2C0%2C2-0.6l4.6-5.1c0.8-0.9%2C0.8-2.3%2C0-3.2L44%2C41z%20M23.5%2C32.8%0A%09c-1%2C0.1-1.7%2C0.9-1.7%2C1.8v0.9c0%2C1%2C0.7%2C1.8%2C1.7%2C1.8h23.4c1-0.1%2C1.7-0.9%2C1.7-1.8v-0.9c0-1-0.7-1.8-1.7-1.9L23.5%2C32.8L23.5%2C32.8z%22/%3E%0A%3C/svg%3E%0A',
                'SIZE' => '100%',
                'POSITION' => 'center',
                'COLOR' => '#eeeeee',
            ],
            'PLACEMENT_HANDLER' => $connectorUrl,
        ]
    );

    $eventRes = CRest::call(
        'event.bind',
        [
            'event' => 'OnImConnectorMessageAdd',
            'handler' => $messagingHandlerUrl,
        ]
    );

    // Dynamically find the Keen Nexus Open Line ID
    $defaultLine = 1;
    $linesRes = CRest::call('imopenlines.config.list.get', []);
    if (!empty($linesRes['result'])) {
        foreach ($linesRes['result'] as $lineInfo) {
            if (stripos($lineInfo['LINE_NAME'] ?? '', 'Nexus') !== false) {
                $defaultLine = $lineInfo['ID'];
                break;
            }
        }
    }

    $activateRes = CRest::call(
        'imconnector.activate',
        [
            'CONNECTOR' => 'keen_nexus',
            'LINE' => $defaultLine,
            'ACTIVE' => 1,
        ]
    );

    $connectorDataRes = CRest::call(
        'imconnector.connector.data.set',
        [
            'CONNECTOR' => 'keen_nexus',
            'LINE' => $defaultLine,
            'DATA' => [
                'id' => 'keen_nexus_line_' . $defaultLine,
                'url_im' => '',
                'name' => 'Keen Nexus WhatsApp',
            ],
        ]
    );

    // Save the active line ID in settings.json so webhook.php can use it reliably
    $settingsFile = __DIR__ . '/settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $settings['open_line_id'] = $defaultLine;
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    }

    $binding_results['connector'] = $connectorRes;
    $binding_results['event'] = $eventRes;
    $binding_results['activate'] = $activateRes;
    $binding_results['connector_data'] = $connectorDataRes;

    CRest::setLog(['placements' => $binding_results], 'installation_bindings');
    $is_installed = true;
} else {
    // If not a POST install, but accessed normally within Bitrix24
    if (isset($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] == 'DEFAULT') {
        // Just show the UI
    } else {
        $error_message = 'Installation failed or invalid request.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KEEN Nexus - Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fa;
            background-image: 
                radial-gradient(at 0% 0%, rgba(67, 97, 238, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(72, 149, 239, 0.05) 0px, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .install-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(67, 97, 238, 0.15);
            padding: 40px;
            max-width: 600px;
            margin: auto;
        }
        h1 { font-family: 'Outfit', sans-serif; font-weight: 700; color: #4361ee; }
        .btn-install { 
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            border: none;
            border-radius: 12px;
            padding: 12px 40px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-install:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(67, 97, 238, 0.3); color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="install-card text-center">
            <div class="mb-4">
                <i class="fab fa-whatsapp fa-4x text-primary"></i>
            </div>
            <h1 class="mb-3">Welcome to KEEN Nexus</h1>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= $error_message ?></div>
            <?php else: ?>
                <p class="text-muted mb-4">Finalizing the secure connection with your Bitrix24 portal. Click the button below to complete the setup.</p>
                <button id="finishBtn" class="btn btn-primary btn-lg btn-install px-5">Complete Setup</button>
            <?php endif; ?>
        
            <div id="status" class="mt-4" style="display:none;">
                <div class="spinner-border text-primary mr-2" role="status"></div>
                <span class="text-muted">Registering secure placements...</span>
            </div>
        
            <div id="nextSteps" class="mt-4" style="<?= $is_installed ? '' : 'display:none;' ?>">
                <div class="alert alert-success border-0 shadow-sm rounded-lg py-3">
                    <i class="fas fa-check-circle mr-2"></i> Setup finished successfully!
                </div>
                <div class="text-left mt-4 text-muted">
                    <p><span class="badge badge-primary mr-2">1</span> Close this window.</p>
                    <p><span class="badge badge-primary mr-2">2</span> <b>Refresh your Bitrix24 page.</b></p>
                    <p><span class="badge badge-primary mr-2">3</span> Open any <b>Lead</b> or <b>Deal</b> to find the <b>"KEEN Nexus"</b> tab.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        BX24.init(function() {
            console.log("BX24 Initialized");
            <?php if ($is_installed): ?>
                document.getElementById('finishBtn').style.display = 'none';
                document.getElementById('nextSteps').style.display = 'block';
                BX24.installFinish();
            <?php endif; ?>
        });

        document.getElementById('finishBtn').addEventListener('click', function() {
            BX24.installFinish();
            document.getElementById('finishBtn').style.display = 'none';
            document.getElementById('nextSteps').style.display = 'block';
        });
    </script>
</body>
</html>
