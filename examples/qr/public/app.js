const socket = io();

// State
let currentClientId = null;
let currentChatId = null;
let isConnected = false;
let bitrixAuth = null;

// Auth Helper
function getAuthParams() {
    if (!bitrixAuth) {
        const urlParams = new URLSearchParams(window.location.search || window.location.hash.substring(1));
        const domain = urlParams.get('domain') || urlParams.get('DOMAIN');
        const authId = urlParams.get('authId') || urlParams.get('AUTH_ID');
        if (domain && authId) {
            bitrixAuth = { domain, authId };
        }
    }
    
    if (bitrixAuth) {
        return `DOMAIN=${encodeURIComponent(bitrixAuth.domain)}&AUTH_ID=${encodeURIComponent(bitrixAuth.authId)}`;
    }
    return '';
}

// DOM Elements
const sessionsList = document.getElementById('sessions-list');
const chatsList = document.getElementById('chats-list');
const chatMessages = document.getElementById('chat-messages');

const clientIdInput = document.getElementById('client-id');
const connectBtn = document.getElementById('connect-btn');
const msgBody = document.getElementById('msg-body');
const sendMsgBtn = document.getElementById('send-msg-btn');
const attachBtn = document.getElementById('attach-btn');
const fileInput = document.getElementById('file-input');
const newChatToggle = document.getElementById('new-chat-toggle');
const newChatArea = document.getElementById('new-chat-area');
const newChatInput = document.getElementById('new-chat-input');
const newChatStart = document.getElementById('new-chat-start');

// Screens
const welcomeScreen = document.getElementById('welcome-screen');
const qrScreen = document.getElementById('qr-screen');
const chatInterface = document.getElementById('chat-interface');

const statusIndicator = document.getElementById('status-indicator');
const statusDot = document.getElementById('status-dot');
const statusText = document.getElementById('status-text');
const qrImage = document.getElementById('qr-image');
const loadingSpinner = document.getElementById('loading-spinner');

// Init
loadSessions();

// === Actions ===

async function loadSessions() {
    try {
        const params = getAuthParams();
        const res = await fetch(`/api/sessions?${params}`);
        const data = await res.json();
        renderSessions(data.clients || []);
    } catch (err) {
        console.error('Failed to load sessions', err);
    }
}

connectBtn.addEventListener('click', () => {
    const cid = clientIdInput.value.trim();
    if (!cid) return;
    initiateSession(cid);
});

async function initiateSession(cid) {
    currentClientId = cid;
    currentChatId = null;
    isConnected = false;

    // Reset UI
    showScreen('qr-screen');
    qrImage.classList.add('hidden');
    loadingSpinner.classList.remove('hidden');
    statusIndicator.classList.remove('hidden');
    setStatus('connecting', 'Connecting...');
    
    document.getElementById('chats-sidebar').style.opacity = '0.5';
    document.getElementById('active-session-label').textContent = cid;
    document.getElementById('active-session-label').classList.remove('hidden');

    try {
        const params = getAuthParams();
        const res = await fetch(`/api/status/${cid}?${params}`);
        const data = await res.json();

        if (data.ready) {
            handleClientReady(cid);
        } else {
            // Start init
            await fetch(`/api/clients/${cid}?${params}`, { method: 'POST' });
        }
    } catch (err) {
        console.error('Error starting session', err);
        setStatus('disconnected', 'Error');
    }
}

async function handleClientReady(cid) {
    if (cid !== currentClientId) return;
    isConnected = true;
    setStatus('connected', 'Connected');
    showScreen('welcome-screen');
    
    document.getElementById('chats-sidebar').style.opacity = '1';
    await loadChats(cid);
}

async function loadChats(cid) {
    const loadingEl = document.getElementById('chats-loading');
    loadingEl.classList.remove('hidden');
    chatsList.innerHTML = '';
    
    try {
        const res = await fetch(`/api/chats/${cid}`);
        const data = await res.json();
        
        loadingEl.classList.add('hidden');
        renderChats(data.chats || []);
    } catch (err) {
        console.error('Failed to load chats', err);
        loadingEl.classList.add('hidden');
        chatsList.innerHTML = '<div class="center-p">Error loading chats</div>';
    }
}

async function selectChat(chatId, chatName) {
    currentChatId = chatId;
    
    document.querySelectorAll('.chats-list .list-item').forEach(el => el.classList.remove('active'));
    const chatEl = document.getElementById(`chat-${chatId}`);
    if (chatEl) chatEl.classList.add('active');

    document.getElementById('current-chat-name').textContent = chatName;
    document.getElementById('current-chat-id').textContent = chatId;
    
    showScreen('chat-interface');
    chatMessages.innerHTML = '<div class="center-p">Loading messages...</div>';

    // Mark as seen
    markAsSeen(currentClientId, chatId);

    try {
        const authParams = getAuthParams();
        const limit = 50;
        const res = await fetch(`/api/chats/${currentClientId}/${encodeURIComponent(chatId)}/messages?limit=${limit}&${authParams}`);
        const data = await res.json();
        
        if (res.status !== 200) {
            chatMessages.innerHTML = `<div class="center-p" style="color:#ef4444;">Error: ${data.error || 'Failed to load messages'}</div>`;
            return;
        }
        
        if (!data.messages || data.messages.length === 0) {
            chatMessages.innerHTML = `<div class="center-p">This chat is empty.</div>`;
            return;
        }
        
        renderMessages(data.messages);
    } catch (err) {
        console.error('Failed to load messages', err);
        chatMessages.innerHTML = '<div class="center-p" style="color:#ef4444;">Network error while loading messages</div>';
    }
}

async function markAsSeen(clientId, chatId) {
    try {
        const authParams = getAuthParams();
        await fetch(`/api/chats/${clientId}/${encodeURIComponent(chatId)}/seen?${authParams}`, { method: 'POST' });
    } catch (err) {
        console.error('Failed to mark as seen', err);
    }
}

sendMsgBtn.addEventListener('click', sendMessage);
msgBody.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
        msgBody.style.height = '40px';
    }
});

msgBody.addEventListener('input', () => {
    msgBody.style.height = '40px';
    const newHeight = Math.min(msgBody.scrollHeight, 120);
    msgBody.style.height = newHeight + 'px';
});

attachBtn.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', handleFileSelected);

newChatToggle.addEventListener('click', () => {
    newChatArea.classList.toggle('hidden');
    if (!newChatArea.classList.contains('hidden')) newChatInput.focus();
});

newChatStart.addEventListener('click', startNewChat);
newChatInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') startNewChat();
});

function startNewChat() {
    const phone = newChatInput.value.trim();
    if (!phone) return;
    
    // Switch to this chat
    selectChat(phone, phone);
    
    newChatArea.classList.add('hidden');
    newChatInput.value = '';
}

async function handleFileSelected(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Reset input
    e.target.value = '';

    const reader = new FileReader();
    reader.onload = async () => {
        const base64Data = reader.result.split(',')[1];
        await sendFile(file, base64Data, reader.result);
    };
    reader.readAsDataURL(file);
}

async function sendFile(file, base64Data, localData = null) {
    if (!isConnected || !currentClientId || !currentChatId) return;

    const tempId = 'temp_' + Date.now();
    // Optimistic UI for media
    appendMessage({
        id: tempId,
        body: file.name,
        type: file.type.startsWith('image/') ? 'image' : 'document',
        hasMedia: true,
        direction: 'outbound',
        timestamp: Math.floor(Date.now() / 1000),
        localData: localData
    });

    try {
        const authParams = getAuthParams();
        const res = await fetch(`/api/send-media?${authParams}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                clientId: currentClientId,
                to: currentChatId,
                data: base64Data,
                mimetype: file.type,
                filename: file.name,
                DOMAIN: bitrixAuth?.domain,
                AUTH_ID: bitrixAuth?.authId
            })
        });
        const data = await res.json();

        if (data.success && data.messageId) {
            const el = document.getElementById(`msg-${tempId}`);
            if (el) {
                el.id = `msg-${data.messageId}`;
                updateMessageStatus(data.messageId, 1);
            }
        }
    } catch (err) {
        console.error('Failed to send file', err);
    }
}

async function sendMessage() {
    if (!isConnected || !currentClientId || !currentChatId) return;
    
    const text = msgBody.value.trim();
    if (!text) return;

    msgBody.value = '';

    const tempId = 'temp_' + Date.now();
    // Optimistic UI
    appendMessage({
        id: tempId,
        body: text,
        direction: 'outbound',
        timestamp: Math.floor(Date.now() / 1000)
    });

    try {
        const authParams = getAuthParams();
        const res = await fetch(`/api/send-message?${authParams}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                clientId: currentClientId, 
                to: currentChatId, 
                message: text,
                DOMAIN: bitrixAuth?.domain, // Add for bodyParser fallback
                AUTH_ID: bitrixAuth?.authId
            })
        });
        const data = await res.json();
        
        if (data.success && data.messageId) {
            // Link temp ID to real ID so socket ACKs find it
            const el = document.getElementById(`msg-${tempId}`);
            if (el) {
                el.id = `msg-${data.messageId}`;
                updateMessageStatus(data.messageId, 1); // 1 = Sent
            }
        }
    } catch (err) {
        console.error('Failed to send message', err);
    }
}

// === Rendering ===

function renderSessions(clients) {
    sessionsList.innerHTML = '';
    if (clients.length === 0) {
        sessionsList.innerHTML = '<div class="center-p">No sessions found</div>';
        return;
    }

    clients.forEach(c => {
        const el = document.createElement('div');
        el.className = 'list-item';
        el.innerHTML = `
            <div class="item-info">
                <div class="item-title">${c.metadata?.name || c.clientId}</div>
                <div class="item-subtitle">Status: ${c.state || 'offline'}</div>
            </div>
        `;
        el.addEventListener('click', () => initiateSession(c.clientId));
        sessionsList.appendChild(el);
    });
}

function renderChats(chats) {
    chatsList.innerHTML = '';
    
    if (chats.length === 0) {
        chatsList.innerHTML = '<div class="center-p">No active chats found</div>';
        return;
    }

    // Sort by timestamp if available
    chats.sort((a,b) => (b.timestamp || 0) - (a.timestamp || 0));

    chats.forEach(chat => {
        const el = document.createElement('div');
        el.className = 'list-item';
        el.id = `chat-${chat.id}`;
        
        let subText = chat.lastMessage ? (chat.lastMessage.body || chat.lastMessage.type) : (chat.isGroup ? 'Group' : 'Contact');
        if (subText.length > 40) subText = subText.substring(0, 37) + '...';
        
        let reactionHtml = '';
        if (chat.lastMessage?.reactions?.length > 0) {
            // Pick the very latest reaction based on our backend sorting
            const latest = chat.lastMessage.reactions[chat.lastMessage.reactions.length - 1];
            reactionHtml = `<span class="sidebar-reaction">${latest.aggregateEmoji}</span>`;
        }

        let unreadHtml = chat.unreadCount > 0 ? `<span class="unread-badge">${chat.unreadCount}</span>` : '';

        el.innerHTML = `
            <div class="item-info">
                <div class="item-header">
                    <div class="item-title">${escapeHTML(chat.name)}</div>
                    <div class="item-time">${chat.timestamp ? new Date(chat.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : ''}</div>
                </div>
                <div class="item-subtitle-row">
                    <div class="item-subtitle">${reactionHtml} ${escapeHTML(subText)}</div>
                    ${unreadHtml}
                </div>
            </div>
        `;
        el.addEventListener('click', () => selectChat(chat.id, chat.name));
        chatsList.appendChild(el);
    });
}

function renderMessages(messages) {
    chatMessages.innerHTML = '';
    messages.forEach(appendMessage);
}

function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g, 
        tag => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[tag])
    );
}

function appendMessage(msg) {
    if (msg.type === 'e2e_notification' || msg.type === 'call_log') {
        return; // Hiding system chatter
    }

    // Check if message already exists (prevent duplicates from socket + initial load)
    if (msg.id && document.getElementById(`msg-${msg.id}`)) {
        const existing = document.getElementById(`msg-${msg.id}`);
        if (msg.ack !== undefined) updateMessageStatus(msg.id, msg.ack);
        return;
    }

    const el = document.createElement('div');
    if (msg.id) el.id = `msg-${msg.id}`;
    
    const isOutbound = msg.direction === 'outbound' || msg.fromMe === true;
    el.className = `message-bubble ${isOutbound ? 'outbound' : 'inbound'}`;
    
    const time = msg.timestamp ? new Date(msg.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '';
    
    let contentHtml = '';
    let rawBody = msg.body || '';

    // Handle Revoked
    if (msg.type === 'revoked') {
        contentHtml = `<span class="body italic" style="color:var(--text-secondary)">🚫 This message was deleted</span>`;
    } 
    // Handle Media
    else if (msg.hasMedia) {
        const chatHandle = encodeURIComponent(currentChatId || msg.from);
        const remoteUrl = `/api/media/${currentClientId}/${chatHandle}/${encodeURIComponent(msg.id)}`;
        const mediaUrl = msg.localData || remoteUrl;
        
        if (msg.type === 'image' || msg.type === 'sticker') {
            contentHtml = `
                <div class="media-content" onclick="window.open('${mediaUrl}', '_blank')">
                    <img src="${mediaUrl}" alt="Media" loading="lazy">
                </div>
                ${rawBody ? `<span class="body">${escapeHTML(rawBody)}</span>` : ''}
            `;
        } else if (msg.type === 'video') {
            contentHtml = `
                <div class="media-content" onclick="window.open('${mediaUrl}', '_blank')">
                    <div class="center-p">🎥 Video</div>
                </div>
                ${rawBody ? `<span class="body">${escapeHTML(rawBody)}</span>` : ''}
            `;
        } else if (msg.type === 'audio' || msg.type === 'ptt') {
            contentHtml = `
                <div class="media-content audio">
                    <audio controls src="${mediaUrl}"></audio>
                </div>
            `;
        } else if (msg.type === 'document') {
            const fileName = msg.filename || 'Document';
            const fileSize = msg.filesize ? `(${(msg.filesize / 1024 / 1024).toFixed(2)} MB)` : '';
            contentHtml = `
                <a href="${mediaUrl}" target="_blank" class="document-item">
                    <div class="document-info">
                        <div class="document-name">${escapeHTML(fileName)}</div>
                        <div class="document-meta">${msg.mimetype || ''} ${fileSize}</div>
                    </div>
                </a>
                ${rawBody ? `<span class="body">${escapeHTML(rawBody)}</span>` : ''}
            `;
        }
    }
    // Handle Location
    else if (msg.type === 'location' && msg.location) {
        const mapUrl = `https://www.google.com/maps?q=${msg.location.latitude},${msg.location.longitude}`;
        contentHtml = `
            <a href="${mapUrl}" target="_blank" class="location-item">
                <div class="location-map-preview">📍 View Location</div>
                <div class="location-details">${escapeHTML(msg.location.description || 'Shared Location')}</div>
            </a>
        `;
    }
    // Handle Contacts (VCards)
    else if (msg.type === 'vcard' || rawBody.includes('BEGIN:VCARD')) {
        const nameMatch = rawBody.match(/FN:(.+?)(?:\r|\n)/);
        const displayName = (nameMatch && nameMatch[1]) ? nameMatch[1].trim() : 'Contact Card';
        contentHtml = `<span class="body">👤 ${escapeHTML(displayName)}</span>`;
    }
    // Default Text
    else {
        contentHtml = `<span class="body">${escapeHTML(rawBody)}</span>`;
    }

    // Status Ticks for outbound
    let ticksHtml = '';
    if (isOutbound) {
        ticksHtml = `<span class="status-ticks" data-ack="${msg.ack || 0}">${renderStatusTicks(msg.ack || 0)}</span>`;
    }

    // Reactions
    let reactionsHtml = '<div class="message-reactions hidden"></div>';
    if (msg.reactions && msg.reactions.length > 0) {
        reactionsHtml = `<div class="message-reactions">${renderReactions(msg.reactions)}</div>`;
    }

    el.innerHTML = `
        ${contentHtml}
        <span class="message-time">
            ${time}
            ${ticksHtml}
        </span>
        ${reactionsHtml}
    `;
    
    chatMessages.appendChild(el);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function renderStatusTicks(ack) {
    // gray-thin, gray-double, blue-double
    if (ack <= 0) return '🕒'; // pending
    if (ack === 1) return '<svg viewBox="0 0 16 11" preserveAspectRatio="xMidYMid meet" class=""><path fill="currentColor" d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.88a.32.32 0 0 1-.484.032l-.358-.325a.32.32 0 0 0-.484.032l-.378.48a.418.418 0 0 0 .036.54l1.32 1.267a.32.32 0 0 0 .484-.032l6.298-8.552a.366.366 0 0 0-.074-.503z"></path></svg>';
    
    let colorClass = ack >= 3 ? 'read' : '';
    return `
        <span class="status-ticks ${colorClass}">
            <svg viewBox="0 0 16 11" width="16" height="11" fill="currentColor"><path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.88a.32.32 0 0 1-.484.032l-.358-.325a.32.32 0 0 0-.484.032l-.378.48a.418.418 0 0 0 .036.54l1.32 1.267a.32.32 0 0 0 .484-.032l6.298-8.552a.366.366 0 0 0-.074-.503z"></path><path d="M11.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.666 9.88a.32.32 0 0 1-.484.032L1.27 7.028a.366.366 0 0 0-.515.041l-.423.492a.366.366 0 0 0 .042.514l3.716 3.144a.32.32 0 0 0 .448-.036l6.472-8.364a.366.366 0 0 0-.074-.503z"></path></svg>
        </span>
    `;
}

function renderReactions(reactions) {
    if (!reactions || reactions.length === 0) return '';
    // Show only the latest reaction
    const latest = reactions[reactions.length - 1];
    return `<span class="reaction-item" title="${latest.senders.length}">${latest.aggregateEmoji}</span>`;
}

function updateMessageStatus(messageId, ack) {
    const el = document.getElementById(`msg-${messageId}`);
    if (!el) return;
    const ticksContainer = el.querySelector('.status-ticks');
    if (ticksContainer) {
        ticksContainer.innerHTML = renderStatusTicks(ack);
    }
}

// === UI Helpers ===

function showScreen(screenId) {
    welcomeScreen.classList.add('hidden');
    qrScreen.classList.add('hidden');
    chatInterface.classList.add('hidden');
    document.getElementById(screenId).classList.remove('hidden');
}

function setStatus(type, msg) {
    statusText.textContent = msg;
    statusDot.className = 'status-dot';
    if (type === 'connecting') statusDot.classList.add('scanning');
    if (type === 'scanning') statusDot.classList.add('scanning');
    if (type === 'connected') statusDot.classList.add('connected');
}

// === Sockets ===

socket.on('whatsapp_qr', (data) => {
    if (data.clientId !== currentClientId) return;
    setStatus('scanning', 'Awaiting Scan');
    loadingSpinner.classList.add('hidden');
    qrImage.src = data.qrCode;
    qrImage.classList.remove('hidden');
});

socket.on('whatsapp_ready', (data) => {
    if (data.clientId !== currentClientId) return;
    handleClientReady(data.clientId);
});

socket.on('whatsapp_message_received', (data) => {
    if (data.clientId !== currentClientId) return;
    
    // Auto-append if we are looking at this chat
    if (currentChatId) {
        const fromNumber = data.from.replace('@c.us', '');
        const currentNumber = currentChatId.replace('@c.us', '');
        
        // Simple match, could be improved for groups
        if (data.from === currentChatId || fromNumber === currentNumber) {
            appendMessage(data);
            // Auto-mark as seen if we are looking at the chat
            markAsSeen(currentClientId, currentChatId);
        }
    }
});

socket.on('whatsapp_message_ack', (data) => {
    if (data.clientId !== currentClientId) return;
    updateMessageStatus(data.messageId, data.ack);
});

socket.on('whatsapp_message_reaction', (data) => {
    if (data.clientId !== currentClientId) return;
    // For simplicity, we just trigger a data refresh or find the bubble
    // Real implementation would find the bubble and append the emoji
    const el = document.getElementById(`msg-${data.messageId}`);
    if (el) {
        const reactionBox = el.querySelector('.message-reactions');
        if (reactionBox) {
            reactionBox.classList.remove('hidden');
            // Overwrite with latest reaction emoji
            reactionBox.innerHTML = `<span class="reaction-item">${data.reaction}</span>`;
        }
    }
});

socket.on('whatsapp_requires_reauth', (data) => {
    if (data.clientId !== currentClientId) return;
    setStatus('disconnected', 'Session expired');
    showScreen('qr-screen');
    qrImage.classList.add('hidden');
    loadingSpinner.classList.remove('hidden');
    // server might auto-trigger qr again, or need a manual push
});

// Global Error Handling for better debugging
window.onerror = function(msg, url, line, col, error) {
    console.error('Global Error: ', msg, ' at ', url, ':', line);
    // If it's a critical error during init, show a message
    if (!isConnected && !currentClientId) {
        const list = document.getElementById('sessions-list');
        if (list) list.innerHTML = `<div class="center-p" style="color:red;">Error: ${msg}</div>`;
    }
    return false;
};
