<?php
$placement = $_REQUEST['PLACEMENT'] ?? '';
$placementOptions = json_decode($_REQUEST['PLACEMENT_OPTIONS'] ?? '{}', true);
$entityId = $placementOptions['ID'] ?? '';
$entityType = ($placement === 'CRM_DEAL_DETAIL_TAB') ? 'deal' : 'lead';
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
    <title>WhatsApp Business Chat</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        :root {
            --primary-color: #25D366;
            --primary-dark: #128C7E;
            --secondary-color: #34B7F1;
            --bg-color: #f0f2f5;
            --text-main: #111b21;
            --text-muted: #667781;
            --glass-bg: rgba(255, 255, 255, 0.9);
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .chat-container {
            width: 100%;
            max-width: 600px;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .chat-header {
            background: var(--primary-color);
            padding: 24px;
            color: white;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .chat-header svg {
            width: 32px;
            height: 32px;
            fill: currentColor;
        }

        .chat-header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .chat-body {
            padding: 24px;
        }

        .contact-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 12px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .avatar {
            width: 48px;
            height: 48px;
            background: #dfe5e7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--text-muted);
            font-size: 18px;
        }

        .contact-details h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .contact-details p {
            margin: 4px 0 0;
            font-size: 14px;
            color: var(--text-muted);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e1e9eb;
            border-radius: 12px;
            font-size: 15px;
            box-sizing: border-box;
            transition: all 0.2s ease;
            outline: none;
            resize: vertical;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.1);
        }

        .btn-send {
            width: 100%;
            padding: 14px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-send:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-send:active {
            transform: translateY(0);
        }

        .btn-send:disabled {
            background: #cccccc;
            cursor: not-allowed;
        }

        #statusMessage {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .status-success {
            background: #dcf8c6;
            color: #075e54;
            border: 1px solid #c7e9af;
        }

        .status-error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .no-phone {
            text-align: center;
            padding: 40px 20px;
        }

        .no-phone svg {
            width: 64px;
            height: 64px;
            fill: #d1d7db;
            margin-bottom: 16px;
        }

        .no-phone h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text-main);
        }

        .no-phone p {
            margin: 8px 0 0;
            font-size: 14px;
            color: var(--text-muted);
        }

        .attachment-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .btn-attach {
            background: none;
            border: none;
            padding: 8px;
            cursor: pointer;
            color: var(--text-muted);
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-attach:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--primary-color);
        }

        .btn-attach svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }

        #filePreview {
            display: none;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #fff;
            border: 1px dashed var(--primary-color);
            border-radius: 8px;
            margin-bottom: 12px;
            font-size: 13px;
        }

        #filePreview .file-name {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-main);
        }

        #filePreview .btn-remove {
            background: none;
            border: none;
            color: #ff4d4d;
            cursor: pointer;
            padding: 4px;
            font-size: 16px;
        }

        .history-section {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e1e9eb;
        }

        .history-section h3 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-main);
        }

        .history-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 10px;
            max-height: 400px;
            overflow-y: auto;
            scroll-behavior: smooth;
        }

        .history-item {
            max-width: 85%;
            padding: 10px 14px;
            border-radius: 18px;
            position: relative;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            align-self: flex-end; /* Sent messages on right */
            border-bottom-right-radius: 4px;
            border: 1px solid rgba(0, 71, 186, 0.1);
        }

        .history-item.inbound {
            align-self: flex-start; /* Received messages on left */
            background: #e7ffdb; /* Light green/whatsapp-like receiver bubble */
            border-bottom-right-radius: 18px;
            border-bottom-left-radius: 4px;
            border: 1px solid rgba(82, 183, 136, 0.2);
        }

        .history-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 2px;
        }

        .history-item.inbound .history-meta {
            flex-direction: row-reverse;
        }

        .history-meta span:last-child {
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 9px;
        }

        .history-item.inbound .history-meta span:last-child {
            color: var(--secondary-color);
        }

        .history-item:not(.inbound) .history-meta span:last-child {
            color: var(--primary-color);
        }

        .history-body {
            font-size: 14px;
            line-height: 1.4;
            color: var(--text-main);
            word-wrap: break-word;
        }

        .history-file {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dotted rgba(0, 0, 0, 0.1);
        }

        .history-file a {
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }

        .history-item.inbound .history-file a {
            color: var(--secondary-color);
        }

        .history-file a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div id="loadingBox" class="chat-container box-loading" style="padding: 40px; text-align: center;">
    Loading Contact Info...
</div>

<div id="errorBox" class="chat-container box-error" style="display: none;">
    <div class="chat-header">
        <h1>WhatsApp Business</h1>
    </div>
    <div class="chat-body">
        <div class="no-phone" style="text-align:center; padding: 40px;">
            <h3>Missing Information</h3>
            <p>This entity has no valid contact or phone number.</p>
            <button class="btn-send" style="margin-top:20px; background: #ccc; color: #333;" onclick="location.reload()">Refresh</button>
        </div>
    </div>
</div>

<div id="chatBox" class="chat-container box-chat" style="display: none;">
    <div class="chat-header">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.414 0 .018 5.396.015 12.03c0 2.12.541 4.191 1.57 6.071L0 24l6.102-1.602a11.803 11.803 0 005.941 1.603h.005c6.634 0 12.032-5.396 12.035-12.03.001-3.218-1.252-6.244-3.528-8.52z"/></svg>
        <h1>WhatsApp Business</h1>
    </div>

    <div class="chat-body">
        <div class="contact-info">
            <div class="avatar" id="contactInitials">?</div>
            <div class="contact-details">
                <h2 id="contactName">Name</h2>
                <p id="contactPhone">Phone</p>
            </div>
        </div>

        <div class="history-section" id="historySection" style="display: none;">
            <h3>Recent Messages</h3>
            <div class="history-list" id="historyList"></div>
        </div>

        <div class="form-group">
            <div class="attachment-wrapper">
                <label for="messageText">Draft Message</label>
                <button type="button" class="btn-attach" id="attachBtn">
                    <svg viewBox="0 0 24 24"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
                    <span>Attach File</span>
                </button>
                <input type="file" id="fileInput" style="display: none;">
            </div>
            <div id="filePreview">
                <span class="file-name" id="fileName"></span>
                <button type="button" class="btn-remove" id="removeFile">&times;</button>
            </div>
            <textarea class="form-control" id="messageText" rows="4" placeholder="Write your message..."></textarea>
        </div>

        <button class="btn-send" id="sendMessageBtn">
            <span class="spinner" id="btnSpinner"></span>
            <span id="btnText">Send WhatsApp</span>
        </button>

        <div id="statusMessage"></div>
    </div>
</div>

<script>
    var entityId = '<?= htmlspecialchars($entityId) ?>';
    var placement = '<?= htmlspecialchars($placement) ?>';
    var entityType = '<?= htmlspecialchars($entityType) ?>';
    var currentPhone = '';
    var lastMessageCount = 0;
    var selectedFile = null;

    $(document).ready(function() {
        // Init BX24
        BX24.init(function() {
            if (!entityId) {
                var info = BX24.placement.info();
                if (info.options && info.options.ID) {
                    entityId = info.options.ID;
                    placement = info.placement;
                    entityType = placement === 'CRM_DEAL_DETAIL_TAB' ? 'deal' : 'lead';
                }
            }

            if (!entityId) {
                showError();
                return;
            }

            if (entityType === 'deal') {
                BX24.callMethod('crm.deal.get', { id: entityId }, function(res) {
                    if (res.error()) { showError(); return; }
                    var deal = res.data();
                    if (deal.CONTACT_ID) {
                        BX24.callMethod('crm.contact.get', { id: deal.CONTACT_ID }, function(resContact) {
                            if (resContact.error()) { showError(); return; }
                            var contact = resContact.data();
                            var name = $.trim((contact.NAME || '') + ' ' + (contact.LAST_NAME || ''));
                            var phone = (contact.PHONE && contact.PHONE.length > 0) ? contact.PHONE[0].VALUE : '';
                            initChat(name, phone);
                        });
                    } else {
                        showError();
                    }
                });
            } else {
                BX24.callMethod('crm.lead.get', { id: entityId }, function(res) {
                    if (res.error()) { showError(); return; }
                    var lead = res.data();
                    var name = $.trim((lead.NAME || '') + ' ' + (lead.LAST_NAME || ''));
                    if (!name) name = lead.TITLE || 'Lead Contact';
                    var phone = (lead.PHONE && lead.PHONE.length > 0) ? lead.PHONE[0].VALUE : '';
                    initChat(name, phone);
                });
            }
        });

        $('#attachBtn').click(function() { $('#fileInput').click(); });

        $('#fileInput').change(function(e) {
            if (e.target.files.length > 0) {
                selectedFile = e.target.files[0];
                $('#fileName').text(selectedFile.name);
                $('#filePreview').css('display', 'flex');
            }
        });

        $('#removeFile').click(function() {
            selectedFile = null;
            $('#fileInput').val('');
            $('#filePreview').hide();
        });

        $('#sendMessageBtn').click(function() {
            var message = $('#messageText').val().trim();
            
            if (!message && !selectedFile) {
                showStatus('Please enter a message or select a file.', 'error');
                return;
            }
            
            setLoading(true);

            var formData = new FormData();
            formData.append('phone', currentPhone);
            formData.append('message', message);
            formData.append('entityId', entityId);
            formData.append('entityType', entityType);
            if (selectedFile) {
                formData.append('file', selectedFile);
            }
            
            $.ajax({
                url: 'send_message.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    showStatus('Message sent successfully!', 'success');
                    
                    addMessageToHistory(message, response.file_url, response.timestamp, 'outbound');
                    scrollToBottom();
                    lastMessageCount++;
                    
                    $('#messageText').val('');
                    $('#removeFile').click();
                    setLoading(false);
                },
                error: function(xhr) {
                    var errorMsg = 'Failed to send message.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg += ' ' + xhr.responseJSON.error;
                    }
                    showStatus(errorMsg, 'error');
                    setLoading(false);
                    console.error('API Error:', xhr.responseText);
                }
            });
        });

        setInterval(pollHistory, 5000);
    });

    function initChat(name, phone) {
        if (!phone) {
            showError();
            return;
        }

        currentPhone = phone;
        $('#loadingBox').hide();
        $('#chatBox').show();

        $('#contactName').text(name || 'Unknown');
        $('#contactPhone').text(phone);

        var initials = '?';
        if (name) {
            var parts = name.split(' ');
            initials = parts.map(p => p.charAt(0).toUpperCase()).join('').substring(0, 2);
        }
        $('#contactInitials').text(initials);

        pollHistory();
    }

    function showError() {
        $('#loadingBox').hide();
        $('#errorBox').show();
    }

    function scrollToBottom() {
        var $list = $('#historyList');
        $list.scrollTop($list[0].scrollHeight);
    }

    function setLoading(isLoading) {
        const $btn = $('#sendMessageBtn');
        const $spinner = $('#btnSpinner');
        const $text = $('#btnText');

        if (isLoading) {
            $btn.prop('disabled', true);
            $spinner.show();
            $text.text('Sending...');
            $('#statusMessage').hide();
        } else {
            $btn.prop('disabled', false);
            $spinner.hide();
            $text.text('Send WhatsApp');
        }
    }

    function showStatus(text, type) {
        const $status = $('#statusMessage');
        $status.removeClass('status-success status-error')
               .addClass(type === 'success' ? 'status-success' : 'status-error')
               .text(text)
               .show();
    }

    function addMessageToHistory(message, fileUrl, timestamp, direction) {
        var isInbound = direction === 'inbound';
        var html = '<div class="history-item ' + (isInbound ? 'inbound' : '') + '" style="display: none;">' +
                   '  <div class="history-meta">' +
                   '    <span>' + timestamp + '</span>' +
                   '    <span>' + (isInbound ? 'Received' : 'Sent') + '</span>' +
                   '  </div>' +
                   '  <div class="history-body">';
        
        if (message) {
            html += '<div class="message-text">' + message.replace(/\n/g, '<br>') + '</div>';
        }
        
        if (fileUrl) {
            html += '<div class="history-file">' +
                    '  <a href="' + fileUrl + '" target="_blank">' +
                    '    <svg viewBox="0 0 24 24" width="14" height="14" style="fill: currentColor;"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>' +
                    '    View Attachment' +
                    '  </a>' +
                    '</div>';
        }
        
        html += '  </div>' +
                '</div>';
        
        var $newMsg = $(html);
        $('#historyList').append($newMsg);
        $newMsg.fadeIn(500);
        
        $('#historySection').show();
    }

    function pollHistory() {
        if (!entityId || !entityType) return;
        $.ajax({
            url: 'get_history.php',
            data: { id: entityId, type: entityType },
            success: function(history) {
                if (Array.isArray(history) && history.length > lastMessageCount) {
                    var newMessages = history.slice(lastMessageCount);
                    newMessages.forEach(function(item) {
                        addMessageToHistory(item.message, item.file_url, item.timestamp, item.direction);
                    });
                    lastMessageCount = history.length;
                    scrollToBottom();
                }
            }
        });
    }
</script>
</body>
</html>
