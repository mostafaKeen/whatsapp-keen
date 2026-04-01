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
app.post('/bitrix/install', async (req, res) => {
    const { DOMAIN, AUTH_ID, REFRESH_ID, AUTH_EXPIRES, SERVER_ENDPOINT } = req.body;
    try {
        await bitrixManager.saveAuth(DOMAIN, { AUTH_ID, REFRESH_ID, AUTH_EXPIRES, SERVER_ENDPOINT });
        res.send(`
            <!DOCTYPE html>
            <html>
                <head><script src="//api.bitrix24.com/api/v1/"></script></head>
                <body>
                    <script>
                        BX24.install(() => {
                            BX24.installFinish();
                        });
                    </script>
                    Installation complete...
                </body>
            </html>
        `);
    } catch (err) {
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
