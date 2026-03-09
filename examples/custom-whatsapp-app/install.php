<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
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
        });

        document.getElementById('finishBtn').addEventListener('click', function() {
            document.getElementById('status').style.display = 'block';
            this.disabled = true;
            
            // Register Placements
            var placementUrl = window.location.href.replace('install.php', 'placement.php');
            var placements = ['CRM_LEAD_DETAIL_TAB', 'CRM_DEAL_DETAIL_TAB'];
            var completedCount = 0;

            placements.forEach(function(pCode) {
                // 1. Get ALL currently bound handlers for this placement
                BX24.callMethod('placement.get', { PLACEMENT: pCode }, function(res) {
                    if (res.data()) {
                        var existing = res.data();
                        existing.forEach(function(item) {
                            // 2. Unbind every single one found
                            BX24.callMethod('placement.unbind', {
                                PLACEMENT: pCode,
                                HANDLER: item.handler
                            });
                        });
                    }
                    
                    // 3. Bind the new one
                    BX24.callMethod('placement.bind', {
                        PLACEMENT: pCode,
                        HANDLER: placementUrl,
                        TITLE: 'KEEN WABA'
                    }, function(bindRes) {
                        completedCount++;
                        console.log('Finished binding ' + pCode, bindRes.data());
                        
                        // 4. Once both are done, finish installation
                        if (completedCount >= placements.length) {
                            console.log('Placements registered. Finishing installation...');
                            BX24.installFinish();
                            
                            document.getElementById('status').style.display = 'none';
                            document.getElementById('nextSteps').style.display = 'block';
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>
