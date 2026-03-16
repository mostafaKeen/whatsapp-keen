<?php
require_once (__DIR__.'/crest.php');

// LOG ACCESS FOR DEBUGGING
file_put_contents(__DIR__ . '/placement_access.log', date('[Y-m-d H:i:s] ') . json_encode($_REQUEST) . "\n", FILE_APPEND);

$placement = $_REQUEST['PLACEMENT'] ?? '';
$placementOptions = json_decode($_REQUEST['PLACEMENT_OPTIONS'] ?? '{}', true);
$entityId = $placementOptions['ID'] ?? '';
$entityType = ($placement === 'CRM_DEAL_DETAIL_TAB') ? 'deal' : 'lead';

$phone = '';
$contactName = '';

if ($entityId) {
    if ($entityType === 'deal') {
        $deal = CRest::call('crm.deal.get', ['id' => $entityId]);
        if (!empty($deal['result']['CONTACT_ID'])) {
            $contact = CRest::call('crm.contact.get', ['id' => $deal['result']['CONTACT_ID']]);
            if (!empty($contact['result'])) {
                $contactName = trim(($contact['result']['NAME'] ?? '') . ' ' . ($contact['result']['LAST_NAME'] ?? ''));
                if (!empty($contact['result']['PHONE'])) {
                    $phone = $contact['result']['PHONE'][0]['VALUE'];
                }
            }
        }
    } else {
        $lead = CRest::call('crm.lead.get', ['id' => $entityId]);
        if (!empty($lead['result'])) {
            $contactName = trim(($lead['result']['NAME'] ?? '') . ' ' . ($lead['result']['LAST_NAME'] ?? ''));
            if (!$contactName) $contactName = $lead['result']['TITLE'] ?? 'Lead Contact';
            if (!empty($lead['result']['PHONE'])) {
                $phone = $lead['result']['PHONE'][0]['VALUE'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
    <title>whatsapp - Business Chat</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="//api.bitrix24.com/api/v1/"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        :root {
            --wa-teal: #075E54;
            --wa-green: #25D366;
            --wa-light-green: #dcf8c6;
            --wa-blue: #34B7F1;
            --bg-color: #f0f2f5;
            --text-main: #111b21;
            --text-muted: #667781;
            --bubble-sent: #dcf8c6;
            --bubble-received: #ffffff;
            --shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
            --shadow-lg: 0 6px 18px rgba(0,0,0,0.06);
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
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            background: var(--wa-teal);
            padding: 14px 20px;
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
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
            padding: 0;
            display: flex;
            flex-direction: column;
            background: #efe7de url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png');
            height: 480px; /* Fixed height for a better widget feel */
        }

        .contact-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            background: #f0f2f5;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .avatar {
            width: 40px;
            height: 40px;
            background: #dfe5e7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #54656f;
            font-size: 16px;
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
            border-color: var(--wa-green);
        }

        .btn-send {
            width: 100%;
            padding: 12px;
            background: var(--wa-green);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-send:hover {
            background: #1ebe5d;
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
            gap: 12px;
            padding: 20px 10px;
            flex: 1; /* Take up all available space */
            overflow-y: auto;
            scroll-behavior: smooth;
        }

        .history-item {
            max-width: 85%;
            padding: 6px 10px 8px;
            border-radius: 8px;
            position: relative;
            background: var(--bubble-sent);
            box-shadow: var(--shadow);
            align-self: flex-end; 
            margin: 2px 16px;
        }

        .history-item.inbound {
            align-self: flex-start;
            background: var(--bubble-received);
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
        <i class="fab fa-whatsapp fa-lg"></i>
        <div>
            <h1 style="font-size: 16px; font-weight: 500;">whatsapp</h1>
            <p style="margin:0; font-size:11px; opacity:0.8;">Online</p>
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

        <div class="history-list" id="historyList"></div>

        <div id="statusMessage" style="margin: 0 16px;"></div>

        <div class="chat-input-area" style="padding: 12px 16px; background: #f0f2f5; margin-top: auto;">
            <div class="form-group" style="margin-bottom: 0;">
                <div class="attachment-wrapper" style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" class="btn-attach" id="attachBtn">
                        <i class="fas fa-plus"></i>
                    </button>
                    <input type="file" id="fileInput" style="display: none;">
                    <textarea class="form-control" id="messageText" rows="1" placeholder="Type a message" style="border-radius: 20px; border: none; flex: 1;"></textarea>
                    
                    <button class="btn-send" id="sendMessageBtn" style="border-radius: 50%; width: 40px; height: 40px; padding: 0; min-width: 40px; flex-shrink: 0;">
                        <i class="fas fa-paper-plane" id="btnText" style="font-size: 14px;"></i>
                        <span class="spinner" id="btnSpinner"></span>
                    </button>
                </div>
                <div id="filePreview" style="margin-top: 8px;">
                    <span class="file-name" id="fileName"></span>
                    <button type="button" class="btn-remove" id="removeFile">&times;</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var entityId = '<?= htmlspecialchars((string)$entityId) ?>';
    var placement = '<?= htmlspecialchars((string)$placement) ?>';
    var entityType = '<?= htmlspecialchars((string)$entityType) ?>';
    var currentPhone = '<?= htmlspecialchars((string)$phone) ?>';
    var currentName = '<?= htmlspecialchars((string)$contactName) ?>';
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

            // If we already have a phone number from PHP pre-fetch, use it immediately
            if (currentPhone) {
                initChat(currentName, currentPhone);
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
                    // showStatus('Message sent successfully!', 'success');
                    
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
        const $icon = $('#btnText');

        if (isLoading) {
            $btn.prop('disabled', true);
            $spinner.show();
            $icon.hide();
            $('#statusMessage').hide();
        } else {
            $btn.prop('disabled', false);
            $spinner.hide();
            $icon.show();
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
            var displayMessage = messageText;
            
            // Special rendering for known types
            if (item.message_type === 'location' && item.extra && item.extra.map_url) {
                displayMessage = '<div class="location-msg">' +
                                 '  <div style="font-weight: 600; margin-bottom: 4px;"><i class="fas fa-map-marker-alt"></i> Location</div>' +
                                 '  <div style="font-size: 13px; opacity: 0.9;">' + (item.extra.name || item.extra.address || 'View on Map') + '</div>' +
                                 '  <a href="' + item.extra.map_url + '" target="_blank" style="display: block; margin-top: 8px; font-size: 12px; color: inherit; text-decoration: underline;">Open in Google Maps</a>' +
                                 '</div>';
            } else if (item.message_type === 'contacts' && item.extra && item.extra.contact_name) {
                var phones = (item.extra.contact_phones || []).join(', ');
                displayMessage = '<div class="contact-card-msg">' +
                                 '  <div style="font-weight: 600; margin-bottom: 4px;"><i class="fas fa-user-circle"></i> Contact Card</div>' +
                                 '  <div style="font-size: 14px;">' + item.extra.contact_name + '</div>' +
                                 '  <div style="font-size: 12px; opacity: 0.8;">' + phones + '</div>' +
                                 '</div>';
            } else if (item.message_type === 'interactive' || item.message_type === 'button') {
                displayMessage = '<div class="interactive-reply-msg">' +
                                 '  <i class="fas fa-reply" style="font-size: 10px; margin-right: 4px; opacity: 0.6;"></i>' +
                                 '  <span>' + messageText.replace(/\n/g, '<br>') + '</span>' +
                                 '</div>';
            } else {
                displayMessage = messageText.replace(/\n/g, '<br>');
            }
            
            html += '<div class="message-text">' + displayMessage + '</div>';
        }
        
        if (item.file_url || item.external_url) {
            var displayUrl = item.external_url || item.file_url;
            var downloadUrl = item.external_url ? (item.external_url + (item.external_url.includes('?') ? '&' : '?') + 'download=true') : item.file_url;
            
            var fileLinkText = 'Download File';
            var fileIcon = '<svg viewBox="0 0 24 24" width="14" height="14" style="fill: currentColor;"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>';
            
            if (item.message_type === 'image') {
                html += '<div class="history-media" style="margin-top: 8px;">' +
                        '  <a href="' + downloadUrl + '" target="_blank">' +
                        '    <img src="' + displayUrl + '" style="max-width: 100%; border-radius: 4px; display: block; max-height: 200px; object-fit: cover;">' +
                        '  </a>' +
                        '</div>';
            } else {
                html += '<div class="history-file">' +
                        '  <a href="' + downloadUrl + '" target="_blank">' +
                           fileIcon +
                        '    ' + fileLinkText +
                        '  </a>' +
                        '</div>';
            }
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
