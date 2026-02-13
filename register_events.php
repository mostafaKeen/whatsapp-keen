<?php
require 'bitrix.php';

$handlerUrl = 'https://keenenter.com/robodesk/handler.php';

// تسجيل استقبال رسالة
callBitrix('event.bind', [
    'event' => 'ONIMCONNECTORMESSAGEADD',
    'handler' => $handlerUrl
]);

// تسجيل تغيير الحالة (اختياري لكن مهم)
callBitrix('event.bind', [
    'event' => 'ONIMCONNECTORSTATUSADD',
    'handler' => $handlerUrl
]);

callBitrix('event.bind', [
    'event' => 'ONIMCONNECTORSTATUSDELETE',
    'handler' => $handlerUrl
]);

echo "Events registered successfully";
