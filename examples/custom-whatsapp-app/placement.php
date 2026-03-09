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
    <title>KEEN WABA - Business Chat</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="//api.bitrix24.com/api/v1/"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-dark: #3a0ca3;
            --accent-color: #4895ef;
            --bg-color: #f8f9fa;
            --text-main: #2b2d42;
            --text-muted: #8d99ae;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(255, 255, 255, 0.8);
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 25px 50px -12px rgba(67, 97, 238, 0.15);
        }

        body {
            font-family: 'Inter', 'Outfit', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(67, 97, 238, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(72, 149, 239, 0.05) 0px, transparent 50%);
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
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .chat-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            padding: 24px 30px;
            color: white;
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
        }

        .chat-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }

        .chat-header svg {
            width: 32px;
            height: 32px;
            fill: currentColor;
        }

        .chat-header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            font-family: 'Outfit', sans-serif;
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
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #d1fae5;
        }

        .status-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
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
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-icon {
            margin-left: 6px;
            font-size: 14px;
        }
        .status-sent { color: #8696a0; }
        .status-delivered { color: #8696a0; }
        .status-read { color: #53bdeb; }
        .status-failed { color: #ea0038; }

        .history-item.inbound .history-meta {
            flex-direction: row;
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
            <i class="fab fa-whatsapp fa-2x"></i>
            <div>
                <h1>KEEN WABA</h1>
                <p style="margin:0; font-size:12px; opacity:0.8; font-weight:500;">Business Chat Integration</p>
            </div>
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
                    
                    // Trigger poll immediately to show the message with its real ID from the server
                    pollHistory();
                    
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

        setInterval(pollHistory, 2000);
        
        // Sneaky tip: poll immediately when user switches back to this tab
        $(window).on('focus', function() {
            pollHistory();
        });
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

    function addMessageToHistory(item) {
        if (!item) return;

        // Backward compatibility for old history formats (string instead of object)
        if (typeof item === 'string') {
            item = {
                message: item,
                timestamp: '',
                direction: 'outbound',
                source: 'unknown',
                status: 'sent'
            };
        }

        if (typeof item !== 'object') {
            console.error("Invalid history item detected:", item);
            return;
        }

        var isInbound = item.direction === 'inbound';
        var source = item.source || 'unknown';
        var sourceLabel = isInbound ? 'Received' : (source === 'crm_chat' ? 'Sent (CRM Chat)' : 'Sent (Widget)');
        var timestamp = item.timestamp || '';
        var messageText = item.message || '';
        
        // WhatsApp Status Ticks
        var statusHtml = '';
        if (!isInbound) {
            switch(item.status) {
                case 'read':
                    statusHtml = '<i class="fas fa-check-double status-icon status-read" title="Read"></i>';
                    break;
                case 'delivered':
                    statusHtml = '<i class="fas fa-check-double status-icon status-delivered" title="Delivered"></i>';
                    break;
                case 'sent':
                case 'enqueued':
                case 'submitted':
                    statusHtml = '<i class="fas fa-check status-icon status-sent" title="Sent"></i>';
                    break;
                case 'failed':
                    statusHtml = '<i class="fas fa-exclamation-circle status-icon status-failed" title="Failed"></i>';
                    break;
                default:
                    // Only show status icon if we have a status, otherwise omit for clean look or show sent
                    if (item.status) statusHtml = '<i class="fas fa-check status-icon status-sent" title="Sent"></i>';
            }
        }

        var msgIdAttr = item.id ? 'data-msg-id="' + item.id + '"' : '';
        var html = '<div class="history-item ' + (isInbound ? 'inbound' : '') + '" ' + msgIdAttr + ' style="display: none;">' +
                   '  <div class="history-meta">' +
                   '    <span>' + timestamp + '</span>' +
                   '    <div class="d-flex align-items-center">' +
                   '        <span>' + sourceLabel + '</span>' +
                            statusHtml +
                   '    </div>' +
                   '  </div>' +
                   '  <div class="history-body">';
        
        if (messageText) {
            html += '<div class="message-text">' + messageText.replace(/\n/g, '<br>') + '</div>';
        }
        
        if (item.file_url) {
            html += '<div class="history-file">' +
                    '  <a href="' + item.file_url + '" target="_blank">' +
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
            data: { id: entityId, type: entityType, _t: Date.now() },
            cache: false,
            success: function(history) {
                if (!Array.isArray(history)) return;

                // Update existing statuses or add new messages
                history.forEach(function(item, index) {
                    if (index < lastMessageCount) {
                        // Locate the existing DOM element by index
                        var $existingByIndex = $('#historyList .history-item').eq(index);
                        
                        // If it doesn't have an ID yet, but the server now has one, backfill it!
                        if (item.id && (!$existingByIndex.attr('data-msg-id') || $existingByIndex.attr('data-msg-id') === 'null')) {
                            $existingByIndex.attr('data-msg-id', item.id);
                            console.log('UI Backfilled ID:', item.id);
                        }

                        // Now try to update status by ID
                        if (item.id) {
                            var $existing = $('.history-item[data-msg-id="' + item.id + '"]');
                            if ($existing.length > 0) {
                                updateMessageStatusUI($existing, item.status);
                            }
                        }
                    } else {
                        // Add new message
                        addMessageToHistory(item);
                        lastMessageCount++;
                        scrollToBottom();
                    }
                });
            }
        });
    }

    function updateMessageStatusUI($el, status) {
        var $meta = $el.find('.history-meta div');
        $meta.find('.status-icon').remove();
        
        var iconHtml = '';
        switch(status) {
            case 'read':
                iconHtml = '<i class="fas fa-check-double status-icon status-read" title="Read"></i>';
                break;
            case 'delivered':
                iconHtml = '<i class="fas fa-check-double status-icon status-delivered" title="Delivered"></i>';
                break;
            case 'sent':
            case 'enqueued':
                iconHtml = '<i class="fas fa-check status-icon status-sent" title="Sent"></i>';
                break;
            case 'failed':
                iconHtml = '<i class="fas fa-exclamation-circle status-icon status-failed" title="Failed"></i>';
                break;
        }
        $meta.append(iconHtml);
    }
</script>
</body>
</html>
