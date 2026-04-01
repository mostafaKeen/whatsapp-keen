const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const qrcode = require('qrcode');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));

const SESSION_SECRET = process.env.SESSION_SECRET || 'default_very_secure_32_chars_long_key_for_aes256';
const ENCRYPTION_ALGORITHM = 'aes-256-gcm';

function encryptData(payload) {
    const key = crypto.createHash('sha256').update(SESSION_SECRET).digest();
    const iv = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv(ENCRYPTION_ALGORITHM, key, iv);
    const encrypted = Buffer.concat([cipher.update(JSON.stringify(payload), 'utf8'), cipher.final()]);
    const tag = cipher.getAuthTag();
    return Buffer.concat([iv, tag, encrypted]).toString('base64');
}

function decryptData(text) {
    try {
        const raw = Buffer.from(text, 'base64');
        const iv = raw.slice(0, 12);
        const tag = raw.slice(12, 28);
        const encrypted = raw.slice(28);
        const key = crypto.createHash('sha256').update(SESSION_SECRET).digest();
        const decipher = crypto.createDecipheriv(ENCRYPTION_ALGORITHM, key, iv);
        decipher.setAuthTag(tag);
        const decrypted = Buffer.concat([decipher.update(encrypted), decipher.final()]);
        return JSON.parse(decrypted.toString('utf8'));
    } catch (err) {
        console.error('decryptData error', err);
        return null;
    }
}

class WhatsAppManager {
    constructor(io) {
        this.io = io;
        this.clients = new Map();
        this.clientStates = new Map();
        this.initializingLock = new Set(); // To prevent overlapping initialization
        this.sessionsDir = path.join(__dirname, 'sessions');
        this.dataDir = path.join(this.sessionsDir, 'data');

        if (!fs.existsSync(this.sessionsDir)) {
            fs.mkdirSync(this.sessionsDir, { recursive: true });
        }

        if (!fs.existsSync(this.dataDir)) {
            fs.mkdirSync(this.dataDir, { recursive: true });
        }

        this.healthInterval = setInterval(() => this.checkClientHealth(), 30 * 1000);
    }

    checkClientHealth() {
        for (const clientId of this.clients.keys()) {
            const client = this.clients.get(clientId);
            if (!client || !client.isReady) {
                this.clientStates.set(clientId, 'needs_reauth');
                this.io.emit('whatsapp_requires_reauth', { clientId });
            }
        }
    }

    // Load and resume sessions from the filesystem
    async resumeSessions() {
        if (!fs.existsSync(this.sessionsDir)) return;

        const sessionFolders = fs.readdirSync(this.sessionsDir, { withFileTypes: true })
            .filter(dirent => dirent.isDirectory() && dirent.name.startsWith('session-'))
            .map(dirent => dirent.name.replace('session-', ''));

        console.log(`Found ${sessionFolders.length} existing sessions to resume.`);

        for (const clientId of sessionFolders) {
            console.log(`[${clientId}] Resuming session...`);
            try {
                await this.initializeClient(clientId);
                // Wait between session initializations to prevent browser resource spikes
                await wait(5000); 
            } catch (err) {
                console.error(`[${clientId}] Failed to resume session:`, err.message);
            }
        }
    }

    async initializeClient(clientId, extraMetadata = {}) {
        if (!clientId) {
            throw new Error('clientId is required');
        }

        // If client is already initializing, don't start another one
        if (this.initializingLock.has(clientId)) {
            console.log(`[${clientId}] Client is already in the middle of initialization.`);
            return;
        }

        // If client already exists and is ready, don't reinitialize
        if (this.clients.has(clientId)) {
            const existingClient = this.clients.get(clientId);
            if (existingClient.isReady) {
                console.log(`[${clientId}] Client is already ready.`);
                this.clientStates.set(clientId, 'connected');
                this.io.emit('whatsapp_ready', { clientId });
                return;
            }
            // If not ready but exists, we might need to destroy it before trying again
            try {
                console.log(`[${clientId}] Existing non-ready client found. Destroying before re-init.`);
                await existingClient.destroy();
            } catch (err) {
                console.error(`[${clientId}] Error destroying existing client:`, err);
            }
            this.clients.delete(clientId);
        }

        console.log(`[${clientId}] Initializing new client...`);
        this.clientStates.set(clientId, 'initializing');
        this.initializingLock.add(clientId);

        const client = new Client({
            authStrategy: new LocalAuth({
                clientId: clientId,
                dataPath: this.sessionsDir
            }),
            puppeteer: {
                headless: true, // Run in background to prevent multiple windows popping up
                executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
                args: [
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-accelerated-2d-canvas',
                    '--no-first-run',
                    '--no-zygote',
                    '--disable-gpu'
                ]
            }
        });

        // Attach properties
        client.isReady = false;
        client.clientId = clientId;

        // Store client in the map
        this.clients.set(clientId, client);

        // Client Events
        client.on('qr', async (qr) => {
            console.log(`[${clientId}] QR Code generated`);
            this.clientStates.set(clientId, 'awaiting_scan');
            try {
                const qrDataUrl = await qrcode.toDataURL(qr);
                this.io.emit('whatsapp_qr', { clientId, qrCode: qrDataUrl });
            } catch (err) {
                console.error(`[${clientId}] Error generating QR code image:`, err);
            }
        });

        client.on('ready', () => {
            console.log(`[${clientId}] WhatsApp is ready!`);
            client.isReady = true;
            this.clientStates.set(clientId, 'connected');
            this.io.emit('whatsapp_ready', { clientId });
            
            // Save metadata
            if (client.info) {
                this.saveClientMetadata(clientId, client.info, extraMetadata);
            }
        });

        client.on('authenticated', () => {
            console.log(`[${clientId}] Authenticated successfully!`);
            this.clientStates.set(clientId, 'authenticated');
            this.io.emit('whatsapp_authenticated', { clientId });
        });

        client.on('auth_failure', msg => {
            console.error(`[${clientId}] Authentication failure:`, msg);
            this.clientStates.set(clientId, 'auth_failure');
            this.io.emit('whatsapp_auth_failure', { clientId, message: msg });
        });

        client.on('disconnected', (reason) => {
            console.log(`[${clientId}] Client disconnected. Reason:`, reason);
            client.isReady = false;
            this.clientStates.set(clientId, 'needs_reauth');
            this.io.emit('whatsapp_disconnected', { clientId, reason });
            this.io.emit('whatsapp_requires_reauth', { clientId });
            // Remove client from memory but keep session files unless explicitly logged out
            this.clients.delete(clientId);
            
            client.destroy().catch(err => console.error(`[${clientId}] Error destroying disconnected client:`, err));
        });

        client.on('message', async msg => {
            // Handle incoming messages
            // Store message locally for the client
            this.saveIncomingMessage(clientId, msg);
            this.io.emit('whatsapp_message_received', {
                clientId,
                id: msg.id._serialized,
                from: msg.from,
                to: msg.to,
                body: msg.body,
                timestamp: msg.timestamp,
                type: msg.type,
                fromMe: msg.fromMe,
                hasMedia: msg.hasMedia
            });
        });

        client.on('message_ack', (msg, ack) => {
            this.io.emit('whatsapp_message_ack', {
                clientId,
                messageId: msg.id._serialized,
                ack: ack
            });
        });

        client.on('message_reaction', (reaction) => {
            const reactionData = {
                messageId: reaction.msgId._serialized,
                reaction: reaction.reaction,
                sender: reaction.senderId,
                read: reaction.read,
                timestamp: reaction.timestamp
            };
            
            // Save to persistent storage
            this.updatePersistentReaction(clientId, reactionData.messageId, reactionData);

            this.io.emit('whatsapp_message_reaction', {
                clientId,
                ...reactionData
            });
        });

        const maxAttempts = 3;
        let attempt = 0;
        let lastInitError = null;

        while (attempt < maxAttempts) {
            attempt++;
            try {
                console.log(`[${clientId}] Initialization attempt ${attempt} of ${maxAttempts}...`);
                await client.initialize();
                console.log(`[${clientId}] Client initialization success on attempt ${attempt}`);
                this.initializingLock.delete(clientId);
                return; // Success!
            } catch (error) {
                lastInitError = error;
                console.error(`[${clientId}] Attempt ${attempt} failed:`, error.message);
                
                const isRetryable = error.message.includes('Requesting main frame too early') || 
                                   error.message.includes('Navigation failed') ||
                                   error.message.includes('Target closed');

                if (isRetryable && attempt < maxAttempts) {
                    const delay = 5000 * attempt;
                    console.log(`[${clientId}] Retrying in ${delay/1000}s...`);
                    await wait(delay);
                } else {
                    // Fatal or max attempts reached
                    break;
                }
            }
        }

        // If we get here, all attempts failed
        console.error(`[${clientId}] All ${maxAttempts} initialization attempts failed.`);
        this.clients.delete(clientId);
        this.clientStates.set(clientId, 'auth_failure');
        this.io.emit('whatsapp_auth_failure', { 
            clientId, 
            message: `Initialization failed after ${maxAttempts} attempts: ${lastInitError.message}` 
        });
        this.initializingLock.delete(clientId);

        // Optional: Clean up corrupted session folders on total failure
        try {
            const sessionPath = path.join(this.sessionsDir, `session-${clientId}`);
            if (fs.existsSync(sessionPath)) {
                console.log(`[${clientId}] Attempting to clear potentially corrupted session folder: ${sessionPath}`);
                try {
                    const oldPath = `${sessionPath}.old-${Date.now()}`;
                    fs.renameSync(sessionPath, oldPath);
                    console.log(`[${clientId}] Successfully moved corrupted session to ${oldPath}`);
                } catch (renameErr) {
                    if (renameErr.code === 'EPERM') {
                        console.error(`[${clientId}] CRITICAL: Cannot clean up session folder because it is LOCKED by another process (Chrome or another Node instance).`);
                        console.error(`[${clientId}] Please manually close all chrome.exe processes and try again.`);
                    } else {
                        console.error(`[${clientId}] Error during folder cleanup:`, renameErr.message);
                    }
                }
            }
        } catch (cleanupErr) {
            console.error(`[${clientId}] Unexpected error during session cleanup check:`, cleanupErr.message);
        }

        throw lastInitError;
    }

    isClientReady(clientId) {
        const client = this.clients.get(clientId);
        return client ? client.isReady : false;
    }

    async sendMessage(clientId, to, message) {
        const client = this.clients.get(clientId);
        if (!client || !client.isReady) {
            throw new Error('Client is not ready or not initialized');
        }

        // Preserve existing suffix if present
        const formattedNumber = to.includes('@') ? to : `${to}@c.us`;
        
        try {
            const result = await client.sendMessage(formattedNumber, message);
            
            // Save to local history
            this.saveOutgoingMessage(clientId, {
                to: formattedNumber,
                body: message,
                timestamp: Math.floor(Date.now() / 1000),
                type: 'text'
            });

            return result;
        } catch (error) {
            console.error(`[${clientId}] Internal WhatsApp error sending to ${formattedNumber}:`, error.message);
            throw error;
        }
    }

    async logoutClient(clientId) {
        const client = this.clients.get(clientId);
        if (client) {
            console.log(`[${clientId}] Logging out...`);
            await client.logout();
            client.isReady = false;
            this.clients.delete(clientId);
        } else {
            console.log(`[${clientId}] Cannot logout, client not in memory. Deleting session files manually...`);
            // The directory exists but client isn't loaded; remove directory
            const sessionPath = path.join(this.sessionsDir, `session-${clientId}`);
            if (fs.existsSync(sessionPath)) {
                 fs.rmSync(sessionPath, { recursive: true, force: true });
            }
        }

        const metadataPath = path.join(this.dataDir, `${clientId}_metadata.enc`);
        if (fs.existsSync(metadataPath)) {
            fs.rmSync(metadataPath, { force: true });
        }

        const messagesPath = path.join(this.dataDir, `${clientId}_messages.json`);
        if (fs.existsSync(messagesPath)) {
            fs.rmSync(messagesPath, { force: true });
        }
    }

    saveClientMetadata(clientId, info, extra = {}) {
        try {
            const metadataPath = path.join(this.dataDir, `${clientId}_metadata.enc`);
            const metadata = {
                clientId,
                pushname: info.pushname || null,
                wid: info.wid ? info.wid._serialized : null,
                phone: info.wid ? info.wid.user : null,
                userId: extra.userId || null,
                name: extra.name || null,
                updatedAt: new Date().toISOString()
            };

            const encoded = encryptData(metadata);
            fs.writeFileSync(metadataPath, encoded, 'utf8');
            console.log(`[${clientId}] Encrypted metadata saved.`);
        } catch (err) {
            console.error(`[${clientId}] Error saving metadata:`, err);
        }
    }

    saveIncomingMessage(clientId, msg) {
        try {
            if (!fs.existsSync(this.dataDir)) {
                fs.mkdirSync(this.dataDir, { recursive: true });
            }
            const messagesPath = path.join(this.dataDir, `${clientId}_messages.json`);
            
            let messages = [];
            if (fs.existsSync(messagesPath)) {
                const content = fs.readFileSync(messagesPath, 'utf8');
                messages = JSON.parse(content);
            }
            
            messages.push({
                direction: 'inbound',
                id: msg.id ? msg.id._serialized : null,
                from: msg.from,
                to: msg.to,
                body: msg.body,
                timestamp: msg.timestamp,
                type: msg.type,
                reactions: [] // Initialize with empty reactions
            });
            
            fs.writeFileSync(messagesPath, JSON.stringify(messages, null, 2));
        } catch (err) {
            console.error(`[${clientId}] Error saving message:`, err);
        }
    }

    saveOutgoingMessage(clientId, message) {
        try {
            const messagesPath = path.join(this.dataDir, `${clientId}_messages.json`);
            let messages = [];
            if (fs.existsSync(messagesPath)) {
                messages = JSON.parse(fs.readFileSync(messagesPath, 'utf8') || '[]');
            }

            messages.push({
                direction: 'outbound',
                id: message.id || null, 
                to: message.to,
                body: message.body,
                timestamp: message.timestamp,
                type: message.type || 'text',
                reactions: []
            });

            fs.writeFileSync(messagesPath, JSON.stringify(messages, null, 2));
        } catch (err) {
            console.error(`[${clientId}] Error saving outgoing message:`, err);
        }
    }
    updatePersistentReaction(clientId, messageId, reactionData) {
        try {
            const messagesPath = path.join(this.dataDir, `${clientId}_messages.json`);
            if (!fs.existsSync(messagesPath)) return;

            const messages = JSON.parse(fs.readFileSync(messagesPath, 'utf8') || '[]');
            const msgIndex = messages.findIndex(m => m.id === messageId);
            
            if (msgIndex !== -1) {
                if (!messages[msgIndex].reactions) messages[msgIndex].reactions = [];
                
                // For reactions, we want to keep only the LATEST reaction per unique user
                const reactions = messages[msgIndex].reactions;
                const existingIndex = reactions.findIndex(r => (r.sender === reactionData.sender || r.senderId === reactionData.sender));
                
                if (reactionData.reaction === "") {
                    // Remove reaction if empty string
                    if (existingIndex !== -1) reactions.splice(existingIndex, 1);
                } else {
                    if (existingIndex !== -1) {
                        reactions[existingIndex] = reactionData;
                    } else {
                        reactions.push(reactionData);
                    }
                }
                
                fs.writeFileSync(messagesPath, JSON.stringify(messages, null, 2));
                console.log(`[${clientId}] Persistent reaction updated for ${messageId}`);
            }
        } catch (err) {
            console.error(`[${clientId}] Error updating persistent reaction:`, err);
        }
    }


    getClientMessages(clientId, { since, limit } = {}) {
        try {
            const messagesPath = path.join(this.dataDir, `${clientId}_messages.json`);
            if (!fs.existsSync(messagesPath)) {
                return [];
            }

            let messages = JSON.parse(fs.readFileSync(messagesPath, 'utf8') || '[]');

            if (since) {
                const sinceTs = Number(since);
                messages = messages.filter((row) => Number(row.timestamp) >= sinceTs);
            }

            if (limit) {
                messages = messages.slice(-Math.abs(Number(limit)));
            }

            return messages;
        } catch (err) {
            console.error(`[${clientId}] Error reading messages:`, err);
            return [];
        }
    }

    async getChats(clientId) {
        const client = this.clients.get(clientId);
        if (!client || !client.isReady) {
            throw new Error('Client is not ready or not initialized');
        }
        try {
            const chats = await client.getChats();
            
            // Batch process reactions for the last messages
            const lastMsgIds = chats
                .filter(c => c.lastMessage && c.lastMessage.id && c.lastMessage.id._serialized && c.lastMessage.hasReaction)
                .map(c => c.lastMessage.id._serialized);

            let allReactions = {};
            if (lastMsgIds.length > 0) {
                try {
                    allReactions = await client.pupPage.evaluate(async (ids) => {
                        const dict = {};
                        for (const id of ids) {
                            try {
                                const msgReactions = await window.Store.Reactions.find(id);
                                if (msgReactions && msgReactions.reactions.length) {
                                    dict[id] = msgReactions.reactions.serialize();
                                }
                            } catch (e) {}
                        }
                        return dict;
                    }, lastMsgIds);
                } catch (e) {
                    console.error('Error batch fetching sidebar reactions:', e);
                }
            }

            const result = [];
            for (const c of chats) {
                let lastMessageData = null;
                if (c.lastMessage) {
                    let reactions = [];
                    const msgId = c.lastMessage.id._serialized;
                    if (allReactions[msgId]) {
                        reactions = this._extractLatestReaction(allReactions[msgId]);
                    }
                    
                    lastMessageData = {
                        body: c.lastMessage.body,
                        type: c.lastMessage.type,
                        timestamp: c.lastMessage.timestamp,
                        fromMe: c.lastMessage.fromMe,
                        reactions: reactions
                    };
                }

                result.push({
                    id: c.id._serialized,
                    name: c.name || c.id.user,
                    unreadCount: c.unreadCount,
                    timestamp: c.timestamp,
                    isGroup: c.isGroup,
                    lastMessage: lastMessageData
                });
            }
            return result;

        } catch (err) {
            console.error(`[${clientId}] Error fetching chats:`, err);
            throw new Error('Failed to fetch chats');
        }
    }

    /**
     * Extract per-user latest reaction from the ReactionList[] returned by msg.getReactions().
     * ReactionList structure (from library):
     *   { id, aggregateEmoji, hasReactionByMe, senders: [Reaction] }
     * Where each Reaction sender has: { id, reaction, timestamp, senderId }
     */
    _extractLatestReaction(reactionLists) {
        if (!reactionLists || !Array.isArray(reactionLists) || reactionLists.length === 0) return [];

        // Flatten all senders across all emoji groups and deduplicate per user
        const latestPerUser = new Map();
        for (const group of reactionLists) {
            if (!group.senders || !Array.isArray(group.senders)) continue;
            for (const sender of group.senders) {
                const sid = sender.senderId || sender.id || 'unknown';
                const existing = latestPerUser.get(sid);
                if (!existing || sender.timestamp > existing.timestamp) {
                    latestPerUser.set(sid, {
                        emoji: sender.reaction || group.aggregateEmoji,
                        timestamp: sender.timestamp,
                        senderId: sid
                    });
                }
            }
        }

        if (latestPerUser.size === 0) return [];

        // Group by emoji for display
        const emojiGroups = new Map();
        latestPerUser.forEach(r => {
            if (!emojiGroups.has(r.emoji)) {
                emojiGroups.set(r.emoji, { aggregateEmoji: r.emoji, senders: [] });
            }
            emojiGroups.get(r.emoji).senders.push(r.senderId);
        });

        return Array.from(emojiGroups.values());
    }

    async getChatMessages(clientId, chatId, limit = 50) {
        const client = this.clients.get(clientId);
        if (!client || !client.isReady) {
            throw new Error('Client is not ready or not initialized');
        }
        try {
            const chat = await client.getChatById(chatId);
            const messages = await chat.fetchMessages({ limit: Number(limit) });
            
            // Collect all message IDs that indicate they have reactions
            const msgIdsWithReactions = messages
                .filter(m => m.hasReaction)
                .map(m => m.id._serialized);

            // Fetch ALL reactions in ONE single evaluation to avoid protocol errors and race conditions
            let allReactions = {};
            if (msgIdsWithReactions.length > 0) {
                try {
                    allReactions = await client.pupPage.evaluate(async (ids) => {
                        const dict = {};
                        for (const id of ids) {
                            try {
                                const msgReactions = await window.Store.Reactions.find(id);
                                if (msgReactions && msgReactions.reactions.length) {
                                    dict[id] = msgReactions.reactions.serialize();
                                }
                            } catch (e) {}
                        }
                        return dict;
                    }, msgIdsWithReactions);
                } catch (e) {
                    console.error('Error batch fetching reactions:', e);
                }
            }

            // Pre-load local message history for merging reactions
            const localMessages = this.getClientMessages(clientId);
            const localReactionsMap = {};
            localMessages.forEach(lm => {
                if (lm.id && lm.reactions) {
                    localReactionsMap[lm.id] = lm.reactions;
                }
            });

            // Map messages synchronously to get their pre-populated reactions
            return messages.map(msg => {
                let formattedReactions = [];
                const msgId = msg.id._serialized;
                
                // Priority 1: Live reactions from the browser store
                if (allReactions[msgId]) {
                    formattedReactions = this._extractLatestReaction(allReactions[msgId]);
                }
                
                // Priority 2: Fallback to local persistent history if live fetch is empty
                if (formattedReactions.length === 0 && localReactionsMap[msgId]) {
                    formattedReactions = localReactionsMap[msgId];
                }

                return {
                    id: msgId,
                    from: msg.from,
                    to: msg.to,
                    body: msg.body,
                    timestamp: msg.timestamp,
                    type: msg.type,
                    fromMe: msg.fromMe,
                    ack: msg.ack,
                    hasMedia: msg.hasMedia,
                    mimetype: msg._data.mimetype || null,
                    filename: msg._data.filename || null,
                    filesize: msg._data.size || null,
                    duration: msg._data.duration || null,
                    location: msg.location ? {
                        latitude: msg.location.latitude,
                        longitude: msg.location.longitude,
                        description: msg.location.description
                    } : null,
                    vCards: msg.vCards || [],
                    reactions: formattedReactions
                };
            });
        } catch (err) {
            console.warn(`[${clientId}] Chat ${chatId} not found or has no messages yet.`);
            return []; // Return empty for new chats
        }
    }

    getClientMetadata(clientId) {
        try {
            const metadataPath = path.join(this.dataDir, `${clientId}_metadata.enc`);
            if (!fs.existsSync(metadataPath)) return null;
            const encrypted = fs.readFileSync(metadataPath, 'utf8');
            return decryptData(encrypted);
        } catch (err) {
            console.error(`[${clientId}] Error reading metadata:`, err);
            return null;
        }
    }

    getAllClients() {
        const sessionFolders = fs.existsSync(this.sessionsDir) ? fs.readdirSync(this.sessionsDir, { withFileTypes: true }) : [];
        const clientIds = new Set();

        sessionFolders.forEach(dirent => {
            if (dirent.isDirectory() && dirent.name.startsWith('session-')) {
                clientIds.add(dirent.name.replace('session-', ''));
            }
        });

        this.clients.forEach((_, id) => clientIds.add(id));

        return Array.from(clientIds).map((id) => ({
            clientId: id,
            state: this.getClientState(id),
            metadata: this.getClientMetadata(id)
        }));
    }

    getClientState(clientId) {
        const state = this.clientStates.get(clientId);
        if (state) return state;

        const client = this.clients.get(clientId);
        if (!client) return 'offline';
        return client.isReady ? 'connected' : 'initializing';
    }

    async forceReauth(clientId) {
        await this.logoutClient(clientId);
        this.clientStates.set(clientId, 'needs_reauth');
        await this.initializeClient(clientId);
    }

    async getMessageMedia(clientId, chatId, messageId) {
        const client = this.clients.get(clientId);
        if (!client || !client.isReady) throw new Error('Client not ready');
        
        try {
            const chat = await client.getChatById(chatId);
            const messages = await chat.fetchMessages({ limit: 100 });
            const msg = messages.find(m => m.id._serialized === messageId);
            
            if (msg && msg.hasMedia) {
                return await msg.downloadMedia();
            }
            throw new Error('Message not found or has no media in recent chat history');
        } catch (err) {
            console.error(`[${clientId}] Error downloading media ${messageId} in chat ${chatId}:`, err.message);
            throw err;
        }
    }

    async markChatAsRead(clientId, chatId) {
        const client = this.clients.get(clientId);
        if (!client || !client.isReady) throw new Error('Client not ready');
        try {
            const chat = await client.getChatById(chatId);
            return await chat.sendSeen();
        } catch (err) {
            console.error(`[${clientId}] Error marking chat ${chatId} as read:`, err);
            throw err;
        }
    }

    async reactToMessage(clientId, chatId, messageId, reaction) {
        const client = this.clients.get(clientId);
        if (!client || !client.isReady) throw new Error('Client not ready');
        try {
            const chat = await client.getChatById(chatId);
            const messages = await chat.fetchMessages({ limit: 50 });
            const msg = messages.find(m => m.id._serialized === messageId);
            if (msg) {
                return await msg.react(reaction);
            }
            throw new Error('Message not found in recent history');
        } catch (err) {
            console.error(`[${clientId}] Error reacting to ${messageId}:`, err.message);
            throw err;
        }
    }

    async sendMedia(clientId, to, base64Data, mimetype, filename, caption) {
        const client = this.clients.get(clientId);
        if (!client || !client.isReady) throw new Error('Client not ready');
        
        try {
            const media = new MessageMedia(mimetype, base64Data, filename);
            const formattedNumber = to.includes('@') ? to : `${to}@c.us`;
            const result = await client.sendMessage(formattedNumber, media, { caption });
            
            this.saveOutgoingMessage(clientId, {
                to: formattedNumber,
                body: caption || filename || 'Sent Media',
                timestamp: Math.floor(Date.now() / 1000),
                type: 'media'
            });

            return result;
        } catch (err) {
            console.error(`[${clientId}] Error sending media to ${to}:`, err.message);
            throw err;
        }
    }
}

module.exports = WhatsAppManager;
