<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finish Installation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="//api.bitrix24.com/api/v1/"></script>
</head>
<body class="p-5 text-center">
    <div class="container">
        <h1 class="mb-4">Finalizing WhatsApp Integration</h1>
        <p class="lead">Click the button below to confirm the connection with your Bitrix24 portal.</p>
        
        <button id="finishBtn" class="btn btn-primary btn-lg px-5">Confirm Installation</button>
        
        <div id="status" class="mt-4 alert alert-info" style="display:none;">
            Sending handshake to Bitrix24...
        </div>
        
        <div id="nextSteps" class="mt-4" style="display:none;">
            <div class="alert alert-success">Handshake sent successfully!</div>
            <p>1. Close this window.</p>
            <p>2. <b>Refresh your Bitrix24 page.</b></p>
            <p>3. Open the app from the left menu <b>"wosol-keen local"</b>.</p>
        </div>
    </div>

    <script>
        BX24.init(function() {
            console.log("BX24 Initialized");
        });

        document.getElementById('finishBtn').addEventListener('click', function() {
            document.getElementById('status').style.display = 'block';
            this.disabled = true;
            
            // Register Placements
            // Using window.location.href to safely get the full current URL
            var placementUrl = window.location.href.replace('install.php', 'placement.php');
            
            // Unbind first to prevent duplicates
            BX24.callMethod('placement.unbind', {
                PLACEMENT: 'CRM_LEAD_DETAIL_TAB',
                HANDLER: placementUrl
            });
            BX24.callMethod('placement.unbind', {
                PLACEMENT: 'CRM_DEAL_DETAIL_TAB',
                HANDLER: placementUrl
            });

            BX24.callMethod('placement.bind', {
                PLACEMENT: 'CRM_LEAD_DETAIL_TAB',
                HANDLER: placementUrl,
                TITLE: 'WhatsApp'
            }, function(res) {
                console.log('Lead tab bind:', res.data());
            });

            BX24.callMethod('placement.bind', {
                PLACEMENT: 'CRM_DEAL_DETAIL_TAB',
                HANDLER: placementUrl,
                TITLE: 'WhatsApp'
            }, function(res) {
                console.log('Deal tab bind:', res.data());
            });

            // This is the CRITICAL call Bitrix24 is waiting for
            BX24.installFinish();
            
            setTimeout(function() {
                document.getElementById('status').style.display = 'none';
                document.getElementById('nextSteps').style.display = 'block';
            }, 1000);
        });
    </script>
</body>
</html>
