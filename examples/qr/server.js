require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const path = require('path');
const fs = require('fs');
const WhatsAppManager = require('./WhatsAppManager');
const bitrixManager = require('./BitrixManager');

// Set Puppeteer executable path for Chrome or Edge
const possiblePaths = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    path.join(process.env.LOCALAPPDATA || '', 'Google/Chrome/Application/chrome.exe'),
    path.join(process.env.LOCALAPPDATA || '', 'Microsoft/Edge/Application/msedge.exe')
];

let browserPath = null;
for (const p of possiblePaths) {
    if (fs.existsSync(p)) {
        browserPath = p;
        process.env.PUPPETEER_EXECUTABLE_PATH = p;
        console.log(`[System] Found browser at: ${p}`);
        break;
    }
}

if (!browserPath) {
    console.warn('[System] No browser found in common paths. Puppeteer will try default locations.');
}

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ limit: '50mb', extended: true }));
app.use(express.static(path.join(__dirname, 'public')));

// Initialize WhatsApp Manager with the Socket.io instance
const waManager = new WhatsAppManager(io);

// Handle Bitrix24 POST requests to the root
app.post('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// Middleware to verify Bitrix24 Auth
async function checkBitrixAuth(req, res, next) {
    const query = req.query || {};
    const body = req.body || {};
    
    const domain = query.DOMAIN || body.DOMAIN || query.domain || body.domain;
    const authId = query.AUTH_ID || body.AUTH_ID || query.authId || body.authId;
    
    // For local development or initial setup without Bitrix
    if (!domain && process.env.NODE_ENV !== 'production') {
        console.warn('[Auth] No domain provided, allowing default session in development mode.');
        return next();
    }
    
    if (!domain || !authId) {
        return res.status(401).json({ error: 'Missing Bitrix24 authentication parameters' });
    }
    
    const isValid = await bitrixManager.verifyAuth(domain, authId);
    if (!isValid) {
        return res.status(401).json({ error: 'Invalid Bitrix24 authentication. Your session may have expired.' });
    }
    
    next();
}

// API Endpoints
app.get('/api/clients', checkBitrixAuth, (req, res) => {
    try {
        const clients = waManager.getAllClients();
        res.json({ clients });
    } catch (err) {
        console.error('Error listing clients:', err);
        res.status(500).json({ error: 'Failed to list clients' });
    }
});

// Alias for frontend compatibility
app.get('/api/sessions', checkBitrixAuth, (req, res) => {
    try {
        const clients = waManager.getAllClients();
        res.json({ clients });
    } catch (err) {
        res.status(500).json({ error: 'Failed' });
    }
});

// Bitrix24 Installation & Placement
app.all('/bitrix/install', async (req, res) => {
    const domain = req.body.DOMAIN || req.query.DOMAIN;
    const authId = req.body.AUTH_ID || req.query.AUTH_ID;
    const refreshId = req.body.REFRESH_ID || req.query.REFRESH_ID;
    const expires = req.body.AUTH_EXPIRES || req.query.AUTH_EXPIRES;
    const endpoint = req.body.SERVER_ENDPOINT || req.query.SERVER_ENDPOINT;

    if (!domain) {
        return res.status(400).send('Missing DOMAIN parameter');
    }

    try {
        let placementStatus = '<li>No token provided for registration.</li>';
        
        if (authId) {
            await bitrixManager.saveAuth(domain, { 
                AUTH_ID: authId, 
                REFRESH_ID: refreshId, 
                AUTH_EXPIRES: expires, 
                SERVER_ENDPOINT: endpoint 
            });

            // Automatically bind placements during install
            const protocol = req.headers['x-forwarded-proto'] || 'http';
            const host = req.headers.host;
            const handlerUrl = `${protocol}://${host}/bitrix/placement`;
            
            const placementResults = await bitrixManager.bindPlacements(domain, authId, handlerUrl);
            placementStatus = placementResults.map(r => `<li>${r.code}: ${r.success ? '✅ Success' : '❌ Skipped (' + (r.error || 'Check Permissions') + ')'}</li>`).join('');
        }
        
        res.send(`
            <!DOCTYPE html>
            <html>
                <head>
                    <meta charset="UTF-8">
                    <title>KEEN Nexus - Setup</title>
                    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
                    <script src="//api.bitrix24.com/api/v1/"></script>
                    <style>
                        body {
                            font-family: 'Outfit', sans-serif;
                            background: #0b141a;
                            color: #e9edef;
                            min-height: 100vh;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin: 0;
                        }
                        .install-card {
                            background: #111b21;
                            border: 1px solid #222d34;
                            border-radius: 20px;
                            padding: 40px;
                            max-width: 500px;
                            width: 90%;
                            text-align: center;
                            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
                        }
                        .icon { color: #00a884; font-size: 64px; margin-bottom: 20px; }
                        h1 { font-weight: 700; font-size: 28px; margin-bottom: 10px; color: #fff; }
                        p { color: #8696a0; line-height: 1.6; margin-bottom: 20px; }
                        .placement-log { 
                            text-align: left; 
                            font-size: 11px; 
                            color: #8696a0; 
                            background: #0d1418; 
                            border: 1px solid #222d34;
                            padding: 10px; 
                            border-radius: 8px; 
                            margin-bottom: 20px;
                            list-style: none;
                        }
                        .btn-finish {
                            background: #00a884;
                            color: #fff;
                            border: none;
                            border-radius: 10px;
                            padding: 14px 40px;
                            font-size: 16px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s;
                            box-shadow: 0 4px 14px rgba(0, 168, 132, 0.3);
                        }
                        .btn-finish:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 168, 132, 0.4); background: #008f6f; }
                        .steps { text-align: left; background: #202c33; padding: 18px; border-radius: 12px; margin-top: 25px; }
                        .step { display: flex; gap: 12px; margin-bottom: 10px; font-size: 13px; color: #e9edef; }
                        .step:last-child { margin-bottom: 0; }
                        .step-num { 
                            background: #00a884; 
                            width: 20px; 
                            height: 20px; 
                            border-radius: 50%; 
                            display: flex; 
                            align-items: center; 
                            justify-content: center; 
                            font-size: 11px; 
                            font-weight: bold;
                            flex-shrink: 0;
                        }
                    </style>
                </head>
                <body>
                    <div class="install-card">
                        <div class="icon">📲</div>
                        <h1>Welcome to KEEN Nexus</h1>
                        <p>We've registered your WhatsApp portal. CRM placements status:</p>
                        
                        <ul class="placement-log">
                            ${placementStatus}
                        </ul>
                        
                        <button id="finishBtn" class="btn-finish">Complete Setup</button>

                        <div class="steps">
                            <div class="step"><span class="step-num">1</span> <span>Click <b>Complete Setup</b>.</span></div>
                            <div class="step"><span class="step-num">2</span> <span>Refresh your Bitrix24 page.</span></div>
                            <div class="step"><span class="step-num">3</span> <span>(Optional) Grant "Application Embedding" scope in app settings if tabs were skipped.</span></div>
                        </div>
                    </div>

                    <script>
                        BX24.init(() => {
                            console.log("Bitrix24 ready");
                        });

                        document.getElementById('finishBtn').addEventListener('click', () => {
                            BX24.installFinish();
                        });
                    </script>
                </body>
            </html>
        `);
    } catch (err) {
        console.error(`[Bitrix] Installation error for ${domain}:`, err);
        res.status(500).send('Installation failed');
    }
});

app.get('/bitrix/placement', async (req, res) => {
    const { DOMAIN, PLACEMENT, PLACEMENT_OPTIONS, AUTH_ID } = req.query;
    if (!DOMAIN || !PLACEMENT) return res.status(400).send('Missing placement data');
    
    const options = JSON.parse(PLACEMENT_OPTIONS || '{}');
    const entityId = options.ID;
    const entityType = PLACEMENT === 'CRM_DEAL_DETAIL_TAB' ? 'deal' : 'lead';
    
    res.redirect(`/placement.html?domain=${DOMAIN}&entityId=${entityId}&entityType=${entityType}&authId=${AUTH_ID}`);
});

app.post('/api/clients/:clientId', checkBitrixAuth, async (req, res) => {
    const { clientId } = req.params;
    const metadata = req.body || {};

    if (!clientId) {
        return res.status(400).json({ error: 'Client ID is required' });
    }

    try {
        waManager.initializeClient(clientId, metadata).catch(err => {
            console.error(`[${clientId}] Background initialization error:`, err);
        });
        res.json({ success: true, message: 'Initialization started.', clientId });
    } catch (error) {
        console.error(`[${clientId}] Error starting client:`, error);
        res.status(500).json({ error: 'Failed to start initialization', details: error.message });
    }
});

app.get('/api/status/:clientId', checkBitrixAuth, (req, res) => {
    const { clientId } = req.params;
    const state = waManager.getClientState(clientId);
    const metadata = waManager.getClientMetadata(clientId);
    const ready = state === 'connected';
    res.json({ clientId, state, ready, metadata });
});

app.get('/api/chats/:clientId', checkBitrixAuth, async (req, res) => {
    const { clientId } = req.params;
    try {
        const chats = await waManager.getChats(clientId);
        res.json({ chats });
    } catch (err) {
        console.error(`[${clientId}] Error fetching chats:`, err.message);
        res.status(500).json({ error: 'Failed' });
    }
});

app.get('/api/chats/:clientId/:chatId/messages', checkBitrixAuth, async (req, res) => {
    const { clientId, chatId } = req.params;
    const { limit = 50 } = req.query;
    try {
        const messages = await waManager.getChatMessages(clientId, chatId, limit);
        res.json({ messages });
    } catch (err) {
        console.error(`[${clientId}] Error fetching messages for chat ${chatId}:`, err.message);
        res.status(500).json({ error: 'Failed' });
    }
});

app.post('/api/send-message', checkBitrixAuth, async (req, res) => {
    const { clientId, to, message } = req.body;
    if (!clientId || !to || !message) {
        return res.status(400).json({ error: 'Missing required fields' });
    }
    try {
        const result = await waManager.sendMessage(clientId, to, message);
        res.json({ success: true, messageId: result.id._serialized });
    } catch (error) {
        console.error(`[${clientId}] Error sending message:`, error);
        res.status(500).json({ error: error.message || 'Failed to send message' });
    }
});

app.post('/api/send-media', checkBitrixAuth, async (req, res) => {
    const { clientId, to, data, mimetype, filename, caption } = req.body;
    if (!clientId || !to || !data || !mimetype) {
        return res.status(400).json({ error: 'Missing required fields' });
    }
    try {
        const result = await waManager.sendMedia(clientId, to, data, mimetype, filename, caption);
        res.json({ success: true, messageId: result.id._serialized });
    } catch (err) {
        console.error(`[${clientId}] Error sending media:`, err.message);
        res.status(500).json({ error: 'Failed' });
    }
});

app.get('/api/media/:clientId/:chatId/:messageId', checkBitrixAuth, async (req, res) => {
    const { clientId, chatId, messageId } = req.params;
    try {
        const media = await waManager.getMessageMedia(clientId, chatId, messageId);
        res.setHeader('Content-Type', media.mimetype);
        if (media.filename) {
            res.setHeader('Content-Disposition', `inline; filename="${media.filename}"`);
        }
        const buffer = Buffer.from(media.data, 'base64');
        res.send(buffer);
    } catch (err) {
        console.error(`[${clientId}] Error fetching media ${messageId}:`, err.message);
        res.status(500).json({ error: 'Failed to fetch media' });
    }
});

app.post('/api/chats/:clientId/:chatId/seen', checkBitrixAuth, async (req, res) => {
    const { clientId, chatId } = req.params;
    try {
        await waManager.markChatAsRead(clientId, chatId);
        res.json({ success: true });
    } catch (err) {
        res.status(500).json({ error: 'Failed' });
    }
});

app.post('/api/message/:clientId/react', checkBitrixAuth, async (req, res) => {
    const { clientId } = req.params;
    const { chatId, messageId, reaction } = req.body;
    try {
        await waManager.reactToMessage(clientId, chatId, messageId, reaction);
        res.json({ success: true });
    } catch (err) {
        console.error(`[${clientId}] Error reacting to message ${messageId}:`, err.message);
        res.status(500).json({ error: 'Failed' });
    }
});

app.delete('/api/logout/:clientId', checkBitrixAuth, async (req, res) => {
    const { clientId } = req.params;
    try {
        await waManager.logoutClient(clientId);
        res.json({ success: true, message: 'Client logged out successfully' });
    } catch (error) {
        console.error(`[${clientId}] Error logging out client:`, error);
        res.status(500).json({ error: 'Failed to logout client' });
    }
});

// Port handling
const args = process.argv.slice(2);
const portArg = args.find(a => a.startsWith('port=') || a.startsWith('--port='))?.split('=')[1];
const PORT = portArg || process.env.PORT || 3000;

server.listen(PORT, () => {
    console.log(`Server is running on port ${PORT}`);
    
    // Resume existing sessions
    waManager.resumeSessions().catch(err => {
        console.error('Error during initial session resumption:', err.message);
    });
});
