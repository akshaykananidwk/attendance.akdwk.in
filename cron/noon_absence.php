<?php
/**
 * Schedule daily after 12:00 PM IST, e.g. cron:
 * 5 12 * * * php /www/wwwroot/attendance.akdwk.in/cron/noon_absence.php
 */
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Asia/Kolkata');
attendance_auto_present($conn, null);
$result = attendance_process_noon_absence($conn, date('Y-m-d'), null);

if (php_sapi_name() === 'cli') {
    echo date('Y-m-d H:i:s') . ' noon_absence processed=' . $result['processed']
        . ' penalized=' . $result['penalized'] . PHP_EOL;
}
