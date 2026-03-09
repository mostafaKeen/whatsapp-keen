<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';

// If Bitrix24 sends installation POST data, handle the placement binding immediately.
$is_installed = false;
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['AUTH_ID'])) {
    
    // Construct the handler URL dynamically
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $path = str_replace('install.php', 'placement.php', $_SERVER['REQUEST_URI']);
    // Remove query params if any
    $path = explode('?', $path)[0];
    
    $handlerBackUrl = $protocol . $host . $path;
    
    $domain = $_POST['DOMAIN'] ?? '';
    $auth_id = $_POST['AUTH_ID'] ?? '';
    
    if ($domain && $auth_id) {
        $baseUrl = 'https://' . $domain . '/rest/';
        
        $placements = ['CRM_LEAD_DETAIL_TAB', 'CRM_DEAL_DETAIL_TAB'];
        
        foreach ($placements as $pCode) {
            $bindUrl = $baseUrl . 'placement.bind.json?auth=' . $auth_id;
            
            $postData = [
                'PLACEMENT' => $pCode,
                'HANDLER' => $handlerBackUrl,
                'TITLE' => 'KEEN WABA'
            ];
            
            $ch = curl_init($bindUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            $response = curl_exec($ch);
            curl_close($ch);
        }
        $is_installed = true;
    } else {
        $error_message = 'Missing domain or auth token during installation.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KEEN WABA - Setup</title>
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
            <h1 class="mb-3">Welcome to KEEN WABA</h1>
            <p class="text-muted mb-4">Finalizing the secure connection with your Bitrix24 portal. Click the button below to complete the setup.</p>
        
            <button id="finishBtn" class="btn btn-primary btn-lg btn-install px-5">Complete Setup</button>
            
            <div id="status" class="mt-4" style="display:none;">
                <div class="spinner-border text-primary mr-2" role="status"></div>
                <span class="text-muted">Registering secure placements...</span>
            </div>
        
            <div id="nextSteps" class="mt-4" style="display:none;">
                <div class="alert alert-success border-0 shadow-sm rounded-lg py-3">
                    <i class="fas fa-check-circle mr-2"></i> Setup finished successfully!
                </div>
                <div class="text-left mt-4 text-muted">
                    <p><span class="badge badge-primary mr-2">1</span> Close this window.</p>
                    <p><span class="badge badge-primary mr-2">2</span> <b>Refresh your Bitrix24 page.</b></p>
                    <p><span class="badge badge-primary mr-2">3</span> Open <b>"KEEN WABA"</b> from your application list.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        BX24.init(function() {
            console.log("BX24 Initialized");
            
            <?php if ($is_installed): ?>
                // If PHP handled the binding, we just finish the install process right away.
                BX24.installFinish();
                document.getElementById('finishBtn').style.display = 'none';
                document.getElementById('nextSteps').style.display = 'block';
            <?php endif; ?>
        });

        document.getElementById('finishBtn').addEventListener('click', function() {
            // Because Bitrix24 loads the iframe with POST data, and this page is install.php,
            // we should have already triggered the PHP block above on load.
            // If the user manually clicks "Complete Setup" without the POST data, 
            // we fallback to finishing the install here, but bindings might fail.
            BX24.installFinish();
            document.getElementById('status').style.display = 'none';
            document.getElementById('nextSteps').style.display = 'block';
        });
    </script>
</body>
</html>
